<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\FilterRequest;
use App\Http\Transformers\UserAgreementsTransformer;
use App\Models\Order;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\UserAgreements\AssetContractLinker;
use App\Services\UserAgreements\ReconciliationReport;
use App\Services\UserAgreements\Reconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * REST API for UserAgreement Program records. Mirrors the surface of the
 * web controller (CRUD + send-for-signature + pregen-pdf + pdf download)
 * so the automations layer — auto-create on lease-end, cost resolver,
 * reminder cron — can drive everything from outside the request cycle.
 *
 * Auth follows the web side: all actions check against the Order policy
 * (Order::view / create / update / delete). No separate UserAgreement
 * policy yet — gating is by role granularity at the procurement level.
 */
class UserAgreementsController extends Controller
{
    public function index(FilterRequest $request): array
    {
        $this->authorize('view', Order::class);

        $allowed_columns = [
            'id',
            'agreement_type',
            'lifecycle_stage',
            'user_id',
            'asset_id',
            'base_program_price',
            'device_cost',
            'top_up_amount',
            'buyout_cost',
            'payment_method',
            'terms_accepted_at',
            'signed_at',
            'created_at',
            'updated_at',
        ];

        $agreements = UserAgreement::with(['user', 'asset.model']);

        foreach (['agreement_type', 'lifecycle_stage', 'user_id', 'asset_id', 'payment_method'] as $field) {
            if ($request->filled($field)) {
                $agreements->where($field, '=', $request->input($field));
            }
        }

        if ($request->filled('open')) {
            $open = filter_var($request->input('open'), FILTER_VALIDATE_BOOLEAN);
            if ($open) {
                $agreements->whereNotIn('lifecycle_stage', ['paid_off', 'closed_buyout', 'closed']);
            } else {
                $agreements->whereIn('lifecycle_stage', ['paid_off', 'closed_buyout', 'closed']);
            }
        }

        if ($request->filled('awaiting_signature') && filter_var($request->input('awaiting_signature'), FILTER_VALIDATE_BOOLEAN)) {
            $agreements->whereIn('lifecycle_stage', ['quoted', 'agreement_sent']);
        }

        if ($request->filled('filter') || $request->filled('search')) {
            $agreements->TextSearch($request->input('filter') ?: $request->input('search'));
        }

        if ($request->input('deleted') === 'true') {
            $agreements->onlyTrashed();
        }

        $offset = ($request->input('offset') > $agreements->count()) ? $agreements->count() : app('api_offset_value');
        $limit  = app('api_limit_value');
        $order  = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $sort   = in_array($request->input('sort'), $allowed_columns) ? e($request->input('sort')) : 'created_at';

        $agreements->orderBy($sort, $order);

        $total      = $agreements->count();
        $agreements = $agreements->skip($offset)->take($limit)->get();

        return (new UserAgreementsTransformer)->transformUserAgreements($agreements, $total);
    }

    public function show(UserAgreement $userAgreement): array
    {
        $this->authorize('view', Order::class);

        return (new UserAgreementsTransformer)->transformUserAgreement(
            $userAgreement->load(['user', 'asset.model', 'checkoutAcceptance'])
        );
    }

    /**
     * Fields a client is allowed to set through store/update. PDF
     * paths, signature stamps, acceptance FK, reminder bookkeeping,
     * and lifecycle timestamps are all set by the server (the model's
     * sendForSignature/markSigned helpers, the pregen artisan, the
     * reminder cron). Keeping them off this list prevents a caller
     * with API access from forging a signed/sent state by hand.
     */
    private const CLIENT_EDITABLE_FIELDS = [
        'agreement_type',
        'user_id',
        'asset_id',
        'lifecycle_stage',
        'base_program_price',
        'device_cost',
        'top_up_amount',
        'buyout_cost',
        'payment_method',
        'installment_count',
        'installment_amount',
        'old_asset_tag',
        'old_serial',
        'lease_contract',
        'notes',
    ];

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Order::class);

        $agreement = new UserAgreement;
        $agreement->fill($request->only(self::CLIENT_EDITABLE_FIELDS));
        $agreement->lifecycle_stage = $request->input('lifecycle_stage', 'eligible');
        $agreement->created_by      = auth()->id();

        if (! $agreement->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $agreement->getErrors()));
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new UserAgreementsTransformer)->transformUserAgreement($agreement->fresh(['user', 'asset.model'])),
            trans('admin/user-agreements/message.created')
        ));
    }

    public function update(Request $request, UserAgreement $userAgreement): JsonResponse
    {
        $this->authorize('update', Order::class);

        $userAgreement->fill($request->only(self::CLIENT_EDITABLE_FIELDS));

        if (! $userAgreement->save()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $userAgreement->getErrors()));
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new UserAgreementsTransformer)->transformUserAgreement($userAgreement->fresh(['user', 'asset.model'])),
            trans('admin/user-agreements/message.updated')
        ));
    }

    public function destroy(UserAgreement $userAgreement): JsonResponse
    {
        $this->authorize('delete', Order::class);

        $userAgreement->delete();

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            null,
            trans('admin/user-agreements/message.deleted')
        ));
    }

    /**
     * Kick the agreement to agreement_sent: creates the
     * CheckoutAcceptance and mails the assigned user with the unsigned
     * PDF attached (if one has been pre-rendered).
     */
    public function sendForSignature(UserAgreement $userAgreement): JsonResponse
    {
        $this->authorize('update', Order::class);

        if (! $userAgreement->asset_id || ! $userAgreement->user_id) {
            return response()->json(Helper::formatStandardApiResponse(
                'error',
                null,
                trans('admin/user-agreements/message.missing_asset_or_user')
            ), 422);
        }

        $acceptance = $userAgreement->sendForSignature();

        if (! $acceptance) {
            return response()->json(Helper::formatStandardApiResponse(
                'error',
                null,
                trans('admin/user-agreements/message.send_failed')
            ), 500);
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new UserAgreementsTransformer)->transformUserAgreement($userAgreement->fresh(['user', 'asset.model', 'checkoutAcceptance'])),
            trans('admin/user-agreements/message.sent', ['id' => $acceptance->id])
        ));
    }

    /**
     * Render the unsigned PDF and persist it. Skipped (200 with skipped
     * payload) when the row is missing asset/user.
     */
    public function pregenPdf(UserAgreement $userAgreement): JsonResponse
    {
        $this->authorize('update', Order::class);

        try {
            $path = $userAgreement->storeUnsignedPdf();
        } catch (\Throwable $e) {
            Log::error('user-agreement pregen-pdf api failed for FA#'.$userAgreement->id, ['exception' => $e]);

            return response()->json(Helper::formatStandardApiResponse(
                'error',
                null,
                $e->getMessage()
            ), 500);
        }

        if (! $path) {
            return response()->json(Helper::formatStandardApiResponse(
                'success',
                ['skipped' => true, 'reason' => 'missing_asset_or_user'],
                trans('admin/user-agreements/message.missing_asset_or_user')
            ));
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new UserAgreementsTransformer)->transformUserAgreement($userAgreement->fresh(['user', 'asset.model'])),
            trans('admin/user-agreements/message.pdf_rendered')
        ));
    }

    /**
     * Return the signed PDF if signed, else stream the unsigned preview.
     * Mirrors UserAgreementsController::downloadPdf so callers get the
     * same bytes regardless of channel.
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

        $pdf      = $userAgreement->renderUnsignedPdfBytes();
        $filename = 'user-agreement-'.$userAgreement->id.'-preview.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * Trigger a Reconciler sweep over every faculty-eligible user, or
     * scope to one with `user_id`. Pass `dry_run=true` to preview the
     * plan without writing. Returns aggregate counts plus the
     * per-user reports for any user that produced changes / plans.
     *
     * Same write path as the nightly `snipeit:user-agreements-reconcile`
     * artisan command — wraps the Reconciler service so the prod path
     * does not require shell access.
     */
    public function reconcile(Request $request, Reconciler $reconciler): JsonResponse
    {
        $this->authorize('update', Order::class);

        $dryRun = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOLEAN);

        $reports = [];
        if ($userId = $request->input('user_id')) {
            $user = User::find($userId);
            if (! $user) {
                return response()->json(Helper::formatStandardApiResponse(
                    'error',
                    null,
                    'User #'.$userId.' not found.'
                ), 404);
            }
            $reports = [$reconciler->reconcileForUser($user, $dryRun)];
        } else {
            $reports = $reconciler->reconcileAll($dryRun);
        }

        $totals = [
            'pickup'   => 0,
            'upgrade'  => 0,
            'purchase' => 0,
            'status'   => 0,
        ];
        $changedReports = [];

        foreach ($reports as $r) {
            if ($dryRun) {
                $totals['pickup']   += $r->plannedPickup;
                $totals['upgrade']  += $r->plannedUpgrade;
                $totals['purchase'] += $r->plannedPurchase;
                $totals['status']   += $r->plannedStatusFlip;
                if ($r->hasPlans()) {
                    $changedReports[] = self::reportToArray($r, $dryRun);
                }
            } else {
                $totals['pickup']   += $r->createdPickup;
                $totals['upgrade']  += $r->createdUpgrade;
                $totals['purchase'] += $r->createdPurchase;
                $totals['status']   += $r->statusFlipped;
                if ($r->hasChanges()) {
                    $changedReports[] = self::reportToArray($r, $dryRun);
                }
            }
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            [
                'dry_run'       => $dryRun,
                'users_scanned' => count($reports),
                'users_changed' => count($changedReports),
                'totals'        => $totals,
                'reports'       => $changedReports,
            ],
            $dryRun
                ? 'Reconciler dry-run complete.'
                : 'Reconciler pass complete.'
        ));
    }

    /** @return array<string, mixed> */
    private static function reportToArray(ReconciliationReport $r, bool $dryRun): array
    {
        return $dryRun ? [
            'user_id'              => $r->userId,
            'planned_pickup'       => $r->plannedPickup,
            'planned_upgrade'      => $r->plannedUpgrade,
            'planned_purchase'     => $r->plannedPurchase,
            'planned_status_flip'  => $r->plannedStatusFlip,
        ] : [
            'user_id'        => $r->userId,
            'created_pickup' => $r->createdPickup,
            'created_upgrade' => $r->createdUpgrade,
            'created_purchase' => $r->createdPurchase,
            'status_flipped' => $r->statusFlipped,
            'created_row_ids' => $r->createdRowIds,
        ];
    }

    /**
     * One-shot migration: walk assets that carry lease info in the
     * legacy Snipe-IT custom fields (Lease Contract Name / ID / End
     * Date) and tie them to real Contract entities via the
     * contract_asset bridge. Idempotent — safe to re-run.
     *
     * Body / query params:
     *   asset_id  optional, scope to one asset
     *   dry_run   "true" for a preview without writes
     */
    public function linkAssetsToContracts(Request $request, AssetContractLinker $linker): JsonResponse
    {
        $this->authorize('update', Order::class);

        $dryRun  = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOLEAN);
        $assetId = $request->input('asset_id');
        $assetId = $assetId === null ? null : (int) $assetId;

        $report = $linker->run($dryRun, $assetId);

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            array_merge(['dry_run' => $dryRun], $report->toArray()),
            $dryRun
                ? 'Asset → contract link dry-run complete.'
                : 'Asset → contract link migration complete.'
        ));
    }
}
