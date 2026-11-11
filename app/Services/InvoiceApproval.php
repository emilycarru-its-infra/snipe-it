<?php

namespace App\Services;

use App\Enums\ActionType;
use App\Models\Actionlog;
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

            $before = $this->signature($invoice);
            $this->guardSignature($invoice, $status, $approvedAt);

            $invoice->approval_status = $status;
            foreach (['is_final_invoice', 'usage_tag', 'notes'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $invoice->{$field} = $field === 'is_final_invoice' ? (bool) $changes[$field] : $changes[$field];
                }
            }

            if ($status === 'approved') {
                // An approval already on the invoice is the attestation of
                // record: saving the row again keeps who signed it and when.
                if ($before['approval_status'] !== 'approved') {
                    $invoice->approved_at = $approvedAt ?? now();
                    $invoice->approved_by = $actor->id;
                }
            } elseif ($status === 'pending') {
                // Re-opening sweeps the approval signature so the audit trail
                // stays honest — an invoice that goes pending → approved →
                // pending shouldn't keep the original approver's name on it.
                $invoice->approved_at = null;
                $invoice->approved_by = null;
            }

            // Once the lessor has been told to pay, the signature behind it
            // is evidence: no status change may rewrite or clear it.
            if (($invoice->okp_status === OkayToPay::SENT || $invoice->okp_sent_at !== null)
                && $invoice->isDirty(['approved_at', 'approved_by'])) {
                throw ValidationException::withMessages([
                    'approval_status' => trans('admin/purchase-orders/general.invoice_approval_okp_sent'),
                ]);
            }

            $dirty = array_keys($invoice->getDirty());
            $invoice->save();
            if ($dirty) {
                $this->log($invoice, $actor, $before, $dirty);
            }

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

    /**
     * The approval signature can only be written onto an invoice that does
     * not already carry one, can only be dated between the invoice and now,
     * and cannot be re-dated once it exists.
     */
    private function guardSignature(OrderInvoice $invoice, string $status, ?Carbon $approvedAt): void
    {
        $sent = $invoice->okp_status === OkayToPay::SENT || $invoice->okp_sent_at !== null;
        $signed = $invoice->approval_status === 'approved';

        if ($approvedAt === null) {
            return;
        }

        if ($status !== 'approved') {
            throw ValidationException::withMessages([
                'approved_at' => trans('admin/purchase-orders/general.invoice_approval_date_needs_approval'),
            ]);
        }

        if ($sent || $signed) {
            $kept = $invoice->approved_at?->toDateString();
            if ($kept !== $approvedAt->toDateString()) {
                throw ValidationException::withMessages([
                    'approved_at' => trans('admin/purchase-orders/general.invoice_approval_already_signed'),
                ]);
            }

            return;
        }

        if ($approvedAt->isFuture()) {
            throw ValidationException::withMessages([
                'approved_at' => trans('admin/purchase-orders/general.invoice_approval_date_future'),
            ]);
        }

        if ($invoice->invoice_date && $approvedAt->lt($invoice->invoice_date->copy()->startOfDay())) {
            throw ValidationException::withMessages([
                'approved_at' => trans('admin/purchase-orders/general.invoice_approval_date_before_invoice'),
            ]);
        }
    }

    /** @return array{approval_status: ?string, approved_at: ?string, approved_by: ?int} */
    private function signature(OrderInvoice $invoice): array
    {
        return [
            'approval_status' => $invoice->approval_status,
            'approved_at' => $invoice->approved_at?->toDateTimeString(),
            'approved_by' => $invoice->approved_by ? (int) $invoice->approved_by : null,
        ];
    }

    /** Every change to an invoice's approval is kept on the order's history. */
    private function log(OrderInvoice $invoice, User $actor, array $before, array $dirty): void
    {
        $after = $this->signature($invoice);
        $meta = [];
        foreach ($after as $field => $value) {
            $meta[$field] = ['old' => $before[$field], 'new' => $value];
        }

        $log = new Actionlog;
        $log->item_type = OrderInvoice::class;
        $log->item_id = $invoice->id;
        $log->target_type = Order::class;
        $log->target_id = $invoice->order_id;
        $log->setAttribute('created_by', $actor->id);
        $log->note = trans('admin/purchase-orders/general.invoice_approval_log', [
            'invoice' => $invoice->invoice_number,
            'from' => $before['approval_status'] ?? '—',
            'to' => $after['approval_status'],
            'fields' => implode(', ', $dirty),
        ]);
        $log->log_meta = json_encode($meta);
        $log->logaction(ActionType::Update);
    }
}
