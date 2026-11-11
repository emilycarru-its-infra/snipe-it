<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Transformers\OrdersTransformer;
use App\Models\OrderInvoice;
use App\Services\InvoiceApproval;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderInvoicesController extends Controller
{
    /**
     * Approve, dispute or reopen an invoice — the Invoice Approval Queue's
     * move, for a reconciliation that has no browser. `approved_at` records
     * the day it was really paid when the approval is entered afterwards;
     * fields left out of the body are left as they are.
     */
    public function approval(Request $request, $invoiceId): JsonResponse
    {
        $this->authorize('procurement.edit');

        try {
            return $this->approve($request, $invoiceId);
        } catch (ValidationException $e) {
            // A refused approval is a client error, not a success-shaped 200.
            return response()->json(Helper::formatStandardApiResponse('error', null, $e->errors()), 422);
        }
    }

    private function approve(Request $request, $invoiceId): JsonResponse
    {
        $validated = $request->validate([
            'approval_status' => 'required|string|in:'.implode(',', InvoiceApproval::STATUSES),
            'approved_at' => 'nullable|date|before_or_equal:now',
            'is_final_invoice' => 'nullable|boolean',
            'usage_tag' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:65535',
        ]);

        $invoice = OrderInvoice::findOrFail($invoiceId);
        $approvedAt = filled($validated['approved_at'] ?? null) ? Carbon::parse($validated['approved_at']) : null;
        unset($validated['approved_at']);

        $invoice = app(InvoiceApproval::class)->apply($invoice, $request->user(), $validated, $approvedAt);

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new OrdersTransformer)->transformInvoice($invoice->fresh()),
            trans('admin/purchase-orders/general.invoice_approval_updated')
        ));
    }
}
