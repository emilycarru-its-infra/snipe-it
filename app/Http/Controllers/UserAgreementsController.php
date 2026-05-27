<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\CheckoutAcceptance;
use App\Models\UserAgreement;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * CRUD and signing actions for User Agreement Program agreements. The
 * read-only ledger lives at /reports/procurement/user-agreement-ledger; this
 * controller owns creation, editing, the explicit Send-for-Signature
 * action, and PDF preview / signed-PDF download.
 */
class UserAgreementsController extends Controller
{
    public function index(): RedirectResponse
    {
        // The ledger already lists every agreement with filters — no
        // point in a second list view that competes with it.
        return redirect()->route('reports.procurement.user-agreement-ledger');
    }

    public function create(Request $request)
    {
        $this->authorize('create', Order::class);

        return view('user-agreements/create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $agreement = new UserAgreement;
        $agreement->fill($request->all());
        $agreement->lifecycle_stage = $request->input('lifecycle_stage', 'eligible');
        $agreement->created_by = auth()->id();

        if (! $agreement->save()) {
            return redirect()->back()->withInput()->withErrors($agreement->getErrors());
        }

        return redirect()->route('user-agreements.show', $agreement)
            ->with('success', trans('admin/user-agreements/message.created'));
    }

    public function show(UserAgreement $userAgreement)
    {
        $this->authorize('view', Order::class);

        return view('user-agreements/show', [
            'agreement' => $userAgreement->load('user', 'asset', 'checkoutAcceptance'),
            'eulaPreview' => $userAgreement->eulaBody(),
        ]);
    }

    public function edit(UserAgreement $userAgreement)
    {
        $this->authorize('update', Order::class);

        return view('user-agreements/edit', ['agreement' => $userAgreement]);
    }

    public function update(Request $request, UserAgreement $userAgreement): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $userAgreement->fill($request->all());

        if (! $userAgreement->save()) {
            return redirect()->back()->withInput()->withErrors($userAgreement->getErrors());
        }

        return redirect()->route('user-agreements.show', $userAgreement)
            ->with('success', trans('admin/user-agreements/message.updated'));
    }

    public function destroy(UserAgreement $userAgreement): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $userAgreement->delete();

        return redirect()->route('reports.procurement.user-agreement-ledger')
            ->with('success', trans('admin/user-agreements/message.deleted'));
    }

    /**
     * Explicit "Send for Signature" — kicks the lifecycle to
     * agreement_sent and creates the linked CheckoutAcceptance. The
     * model's saved() hook also handles this path when someone edits
     * the stage directly, so this stays idempotent.
     */
    /**
     * On-demand bulk pre-gen — same logic as the scheduled artisan
     * command `snipeit:user-pregen-pdfs`, fired from a UI button.
     *
     * The scheduler runs this command at 05:00 daily. This handler
     * exists so Sohee can pre-gen on her own cadence (e.g. right
     * before opening the summer User Agreement Program) without
     * waiting for the next 05:00.
     */
    public function pregenAll(Request $request): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $force = $request->boolean('force');
        $all   = $request->boolean('include_sent');

        $stages = $all ? ['eligible', 'quoted', 'agreement_sent'] : ['eligible', 'quoted'];

        $query = UserAgreement::query()
            ->whereIn('lifecycle_stage', $stages)
            ->whereNotNull('user_id')
            ->whereNotNull('asset_id');

        if (! $force) {
            $query->whereNull('pdf_path');
        }

        $agreements = $query->with(['user', 'asset.model'])->get();

        $rendered = 0;
        $skipped  = 0;
        $errors   = 0;
        foreach ($agreements as $agreement) {
            try {
                $path = $agreement->storeUnsignedPdf();
                if ($path) {
                    $rendered++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors++;
                \Log::error('user-pregen on-demand failed for FA#'.$agreement->id, ['exception' => $e]);
            }
        }

        return redirect()->route('reports.procurement.user-agreement-ledger')
            ->with('success', trans('admin/user-agreements/message.pregen_done', [
                'rendered' => $rendered,
                'skipped'  => $skipped,
                'errors'   => $errors,
            ]));
    }

    public function sendForSignature(UserAgreement $userAgreement): RedirectResponse
    {
        $this->authorize('update', Order::class);

        if (! $userAgreement->asset_id || ! $userAgreement->user_id) {
            return redirect()->route('user-agreements.show', $userAgreement)
                ->with('error', trans('admin/user-agreements/message.missing_asset_or_user'));
        }

        $acceptance = $userAgreement->sendForSignature();

        if (! $acceptance) {
            return redirect()->route('user-agreements.show', $userAgreement)
                ->with('error', trans('admin/user-agreements/message.send_failed'));
        }

        return redirect()->route('user-agreements.show', $userAgreement)
            ->with('success', trans('admin/user-agreements/message.sent', ['id' => $acceptance->id]));
    }

    /**
     * Download a PDF for this agreement. If the assigned user has
     * signed, return the stored signed PDF. Otherwise render an
     * unsigned preview through Snipe's existing TCPDF generator so the
     * admin can review what the the user will see.
     */
    public function downloadPdf(UserAgreement $userAgreement)
    {
        $this->authorize('view', Order::class);

        if ($userAgreement->signed_pdf_path) {
            $path = 'private_uploads/eula-pdfs/'.$userAgreement->signed_pdf_path;
            if (Storage::exists($path)) {
                return Storage::download($path, $userAgreement->signed_pdf_path);
            }
        }

        return $this->renderUnsignedPdf($userAgreement);
    }

    private function renderUnsignedPdf(UserAgreement $userAgreement): Response
    {
        $pdf = $userAgreement->renderUnsignedPdfBytes();
        $filename = 'user-agreement-'.$userAgreement->id.'-preview.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
