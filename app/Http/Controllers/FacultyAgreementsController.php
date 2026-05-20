<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\CheckoutAcceptance;
use App\Models\FacultyAgreement;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * CRUD and signing actions for Faculty Laptop Program agreements. The
 * read-only ledger lives at /reports/procurement/faculty-ledger; this
 * controller owns creation, editing, the explicit Send-for-Signature
 * action, and PDF preview / signed-PDF download.
 */
class FacultyAgreementsController extends Controller
{
    public function index(): RedirectResponse
    {
        // The ledger already lists every agreement with filters — no
        // point in a second list view that competes with it.
        return redirect()->route('reports.procurement.faculty-ledger');
    }

    public function create(Request $request)
    {
        $this->authorize('create', Order::class);

        return view('faculty-agreements/create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $agreement = new FacultyAgreement;
        $agreement->fill($request->all());
        $agreement->lifecycle_stage = $request->input('lifecycle_stage', 'eligible');
        $agreement->created_by = auth()->id();

        if (! $agreement->save()) {
            return redirect()->back()->withInput()->withErrors($agreement->getErrors());
        }

        return redirect()->route('faculty-agreements.show', $agreement)
            ->with('success', trans('admin/faculty-agreements/message.created'));
    }

    public function show(FacultyAgreement $facultyAgreement)
    {
        $this->authorize('view', Order::class);

        return view('faculty-agreements/show', [
            'agreement' => $facultyAgreement->load('user', 'asset', 'checkoutAcceptance'),
            'eulaPreview' => $facultyAgreement->eulaBody(),
        ]);
    }

    public function edit(FacultyAgreement $facultyAgreement)
    {
        $this->authorize('update', Order::class);

        return view('faculty-agreements/edit', ['agreement' => $facultyAgreement]);
    }

    public function update(Request $request, FacultyAgreement $facultyAgreement): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $facultyAgreement->fill($request->all());

        if (! $facultyAgreement->save()) {
            return redirect()->back()->withInput()->withErrors($facultyAgreement->getErrors());
        }

        return redirect()->route('faculty-agreements.show', $facultyAgreement)
            ->with('success', trans('admin/faculty-agreements/message.updated'));
    }

    public function destroy(FacultyAgreement $facultyAgreement): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $facultyAgreement->delete();

        return redirect()->route('reports.procurement.faculty-ledger')
            ->with('success', trans('admin/faculty-agreements/message.deleted'));
    }

    /**
     * Explicit "Send for Signature" — kicks the lifecycle to
     * agreement_sent and creates the linked CheckoutAcceptance. The
     * model's saved() hook also handles this path when someone edits
     * the stage directly, so this stays idempotent.
     */
    public function sendForSignature(FacultyAgreement $facultyAgreement): RedirectResponse
    {
        $this->authorize('update', Order::class);

        if (! $facultyAgreement->asset_id || ! $facultyAgreement->user_id) {
            return redirect()->route('faculty-agreements.show', $facultyAgreement)
                ->with('error', trans('admin/faculty-agreements/message.missing_asset_or_user'));
        }

        $acceptance = $facultyAgreement->sendForSignature();

        if (! $acceptance) {
            return redirect()->route('faculty-agreements.show', $facultyAgreement)
                ->with('error', trans('admin/faculty-agreements/message.send_failed'));
        }

        return redirect()->route('faculty-agreements.show', $facultyAgreement)
            ->with('success', trans('admin/faculty-agreements/message.sent', ['id' => $acceptance->id]));
    }

    /**
     * Download a PDF for this agreement. If the faculty member has
     * signed, return the stored signed PDF. Otherwise render an
     * unsigned preview through Snipe's existing TCPDF generator so the
     * admin can review what the faculty will see.
     */
    public function downloadPdf(FacultyAgreement $facultyAgreement)
    {
        $this->authorize('view', Order::class);

        if ($facultyAgreement->signed_pdf_path) {
            $path = 'private_uploads/eula-pdfs/'.$facultyAgreement->signed_pdf_path;
            if (Storage::exists($path)) {
                return Storage::download($path, $facultyAgreement->signed_pdf_path);
            }
        }

        return $this->renderUnsignedPdf($facultyAgreement);
    }

    private function renderUnsignedPdf(FacultyAgreement $facultyAgreement): Response
    {
        $settings = Setting::getSettings();
        $variables = $facultyAgreement->mergeVariables();

        $data = [
            'item_tag' => $variables['asset_tag'],
            'item_name' => $variables['model'],
            'item_model' => $variables['model'],
            'item_serial' => $variables['serial'],
            'item_status' => null,
            'eula' => $facultyAgreement->eulaBody(),
            'note' => null,
            'check_out_date' => Helper::getFormattedDateObject(now(), 'datetime', false),
            'accepted_date' => '',
            'declined_date' => '',
            'assigned_to' => $variables['faculty_name'],
            'email' => (string) ($facultyAgreement->user?->email ?? ''),
            'employee_num' => (string) ($facultyAgreement->user?->employee_num ?? ''),
            'site_name' => $settings->site_name,
            'company_name' => $settings->site_name,
            'signature' => null,
            'logo' => null,
            'date_settings' => $settings->date_display_format,
            'qty' => 1,
        ];

        $acceptance = $facultyAgreement->checkoutAcceptance ?: new CheckoutAcceptance;
        $pdf = $acceptance->generateAcceptancePdf($data, 'preview.pdf');

        $filename = 'faculty-agreement-'.$facultyAgreement->id.'-preview.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
