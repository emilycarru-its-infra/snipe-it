<?php

namespace App\Services;

use App\Models\Company;
use App\Models\OkayToPayDelivery;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\User;
use App\Services\Leasing\OkayToPay;
use App\Services\Leasing\OkayToPayAcceptance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mark an invoice approved to pay, disputed or back to pending.
 *
 * One path for the Invoice Approval Queue and the API, so the OK to pay
 * guards cannot be skipped by whichever door the change comes through.
 */
class InvoiceApproval
{
    public const STATUSES = ['pending', 'approved', 'disputed'];

    /**
     * `$changes` carries `approval_status` and, when present, any of
     * `is_final_invoice`, `usage_tag` and `notes`; a key left out leaves that
     * field alone. `$approvedAt` dates an approval — a payment recorded after
     * the fact keeps the day it was really paid — and defaults to now.
     */
    public function apply(OrderInvoice $invoice, User $actor, array $changes, ?Carbon $approvedAt = null): OrderInvoice
    {
        return DB::transaction(function () use ($invoice, $actor, $changes, $approvedAt) {
            Order::whereKey($invoice->order_id)->lockForUpdate()->first();
            $invoice = OrderInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless(Company::isCurrentUserHasAccess($invoice->order), 404);

            $status = $changes['approval_status'];
            $okp = app(OkayToPay::class);
            $evidence = app(OkayToPayAcceptance::class);
            $evidence->lockFacts($invoice);

            if ($status === 'approved' && $invoice->okp_status !== OkayToPay::SENT
                && ($invoice->okp_status !== null || $okp->applies($invoice))) {
                $reasons = $okp->mismatches($invoice);
                if (OkayToPayDelivery::where('invoice_id', $invoice->id)->exists()) {
                    $reasons[] = trans('admin/purchase-orders/general.okp_reason_delivery_reserved');
                }
                if ($reasons) {
                    throw ValidationException::withMessages(['approval_status' => implode(' ', $reasons)]);
                }
            }

            $invoice->approval_status = $status;
            foreach (['is_final_invoice', 'usage_tag', 'notes'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $invoice->{$field} = $field === 'is_final_invoice' ? (bool) $changes[$field] : $changes[$field];
                }
            }

            if ($status === 'approved') {
                $invoice->approved_at = $approvedAt ?? now();
                $invoice->approved_by = $actor->id;
            } elseif ($status === 'pending') {
                // Re-opening sweeps the approval signature so the audit trail
                // stays honest — an invoice that goes pending → approved →
                // pending shouldn't keep the original approver's name on it.
                $invoice->approved_at = null;
                $invoice->approved_by = null;
            }

            $invoice->save();

            if ($status === 'disputed' && $invoice->okp_status !== OkayToPay::SENT
                && ! OkayToPayDelivery::where('invoice_id', $invoice->id)->exists()
                && $evidence->latest($invoice)?->decision === 'accept') {
                $evidence->record($invoice, $actor, [
                    'decision' => 'revoke', 'fingerprint' => $evidence->fingerprint($invoice),
                    'expected_acceptance_id' => $evidence->latest($invoice)->id,
                ]);
            }

            return $invoice;
        });
    }
}
