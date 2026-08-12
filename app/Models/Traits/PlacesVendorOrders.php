<?php

namespace App\Models\Traits;

use App\Models\RequisitionItem;
use App\Services\CdwAccounts;

/**
 * Everything about putting an order to the vendor and recording what comes back.
 *
 * Shared because two records need it for different reasons. The **purchase
 * order** is the document an order is actually placed from — it authorises the
 * spending and the vendor bills against it — so that is where the send lives.
 * The **requisition** is transient: it exists to be keyed into Colleague and to
 * carry the REQM until finance issues the PO, after which it is a tracking
 * record. It still has to show the same facts, so it reads them through here
 * rather than reimplementing them differently and drifting.
 *
 * The host provides `vendorOrderLines()` — for a purchase order, the lines of
 * every requisition that resolved to it; for a requisition, its own.
 */
trait PlacesVendorOrders
{
    /**
     * Whether this can be sent to the vendor as an order.
     *
     * Each gate is a round trip through the vendor's desk if it is missing:
     *
     *   the account      which of the four, and so which blanket purchase order
     *                    and whether ECU or CSI Leasing is invoiced. CDW cannot
     *                    infer it. A CSI-financed account also needs the
     *                    quarter's schedule so the invoice reaches the right
     *                    Exhibit A
     *   the part numbers both of them on catalog lines. The manufacturer's
     *                    number says which product; CDW's own number (the EDC)
     *                    is the thing they place
     *
     * Estimated prices are deliberately not a gate: ours are estimates by
     * design and the returned quote is the authoritative number.
     */
    public function readyForVendor(): bool
    {
        return $this->vendorOrderLines()->isNotEmpty()
            && $this->fundingResolved()
            && $this->linesMissingPartNumbers()->isEmpty();
    }

    /**
     * An account, and — when that account is billed to CSI Leasing rather than
     * to ECU — the schedule the invoice lands on. Both lease accounts need one,
     * admin and curriculum alike.
     */
    public function fundingResolved(): bool
    {
        if (! CdwAccounts::canonical($this->funding_account)) {
            return false;
        }

        return ! CdwAccounts::needsSchedule($this->funding_account) || filled($this->lease_schedule);
    }

    /** "Purchase · Admin" — enough for a column or a chip. */
    public function fundingLabel(): string
    {
        if (! CdwAccounts::canonical($this->funding_account)) {
            return trans('admin/store/general.funding_unset');
        }

        return trim(CdwAccounts::kindLabel($this->funding_account).' · '.CdwAccounts::scopeLabel($this->funding_account), ' ·');
    }

    /**
     * The account as the vendor's desk needs to read it, account number and
     * all, so they can match it to the right blanket purchase order without
     * asking us which one we meant.
     */
    public function fundingDescription(): string
    {
        return CdwAccounts::label($this->funding_account);
    }

    /**
     * Catalog lines the vendor could not place: missing the MFR# or the EDC.
     *
     * Free-form lines are exempt, because two legitimate kinds of line have no
     * part numbers to give. Charges — freight, the BC environmental handling fee
     * — appear on CDW's own quotes with a CDW number and no manufacturer's
     * number, because nobody manufactures a delivery. And a spec combination we
     * have never bought has neither number anywhere yet: CDW's desk prices it
     * and issues them. Blocking those would push exactly the awkward orders back
     * into email, which is the thing this replaces.
     *
     * @return \Illuminate\Support\Collection<int, RequisitionItem>
     */
    public function linesMissingPartNumbers()
    {
        return $this->vendorOrderLines()
            ->filter(fn (RequisitionItem $line) => $line->catalog_item_id !== null
                && (blank($line->vendor_sku) || blank($line->mfr_part_number)))
            ->values();
    }

    /**
     * Lines CDW has to price and number themselves. Called out in the order
     * rather than left to be noticed, so their desk knows which lines need an
     * EDC issuing and which are simply charges.
     *
     * @return \Illuminate\Support\Collection<int, RequisitionItem>
     */
    public function specialRequestLines()
    {
        return $this->vendorOrderLines()
            ->filter(fn (RequisitionItem $line) => $line->catalog_item_id === null
                && blank($line->vendor_sku) && blank($line->mfr_part_number))
            ->values();
    }

    /**
     * Catalog lines whose part numbers have not been checked against the
     * vendor's current list this quarter.
     *
     * CDW reissues EDCs and part numbers even when the product itself has not
     * changed, and always when a custom build is repriced, so a shelf row can be
     * quietly wrong without anybody touching it. A warning, never a gate: a
     * stale number is their desk asking a question, not a wrong order.
     *
     * @return \Illuminate\Support\Collection<int, RequisitionItem>
     */
    public function linesWithStalePartNumbers()
    {
        $cutoff = now()->subDays(self::PART_NUMBER_STALE_DAYS);

        return $this->vendorOrderLines()
            ->filter(function (RequisitionItem $line) use ($cutoff) {
                if ($line->catalog_item_id === null) {
                    return false;
                }

                $verified = $line->catalogItem?->part_numbers_verified_at;

                return $verified === null || $verified->lt($cutoff);
            })
            ->values();
    }

    /**
     * What the vendor's authoritative cost is, once they have quoted: their
     * number when we hold it, our own arithmetic until then.
     */
    public function vendorTotal(): float
    {
        return $this->quote_total !== null ? (float) $this->quote_total : $this->orderLinesTotal();
    }

    /** Line items before tax, from whatever this record's lines are. */
    public function orderLinesTotal(): float
    {
        return round($this->vendorOrderLines()->sum(fn (RequisitionItem $line) => $line->lineTotal()), 2);
    }

    /**
     * Where the order stands with the vendor, as one word.
     *
     * Their loop is not a single "ordered" flag — CDW's rep set it out as seven
     * steps, and the four recorded here are the ones where somebody has to
     * decide something. Read newest-first, because the latest fact is the state.
     * An order number arriving ends the loop and outranks all of it.
     */
    public function vendorStage(): string
    {
        return match (true) {
            filled($this->vendor_order_number) => 'placed',
            $this->quote_confirmed_at !== null => 'confirmed',
            $this->vendor_changes_at !== null => 'changes',
            $this->vendor_sent_at !== null => 'sent',
            $this->readyForVendor() => 'ready',
            default => 'incomplete',
        };
    }

    /**
     * Everyone who should be copied on the order: whoever asked for it through
     * the store, plus any address typed into the send form.
     *
     * A store request has a person waiting at the end of it. Once their lines
     * are folded into a bulk order, this is the thread that tells them it was
     * placed.
     *
     * @return array<int, string>
     */
    public function orderCcAddresses(): array
    {
        return collect($this->storeOrderRequesterEmails())
            ->merge($this->orderCcUsers()->map(fn ($user) => $user->email)->all())
            ->merge(self::splitAddresses($this->order_cc))
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * People picked to be copied, as users rather than as typed addresses.
     *
     * Stored as ids so a name change or a new address follows the person, and so
     * the picker can show who was chosen last time instead of a string somebody
     * has to read to recognise.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    public function orderCcUsers()
    {
        // Only the purchase order carries the column: it is the record an order
        // is sent from. A requisition asked for its CC list returns nobody
        // rather than reaching for a property it does not have.
        $ids = collect(explode(',', (string) ($this->getAttribute('order_cc_users') ?? '')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->all();

        return $ids === [] ? collect() : \App\Models\User::whereIn('id', $ids)->get();
    }

    /**
     * Addresses as typed — comma, semicolon or newline separated, because people
     * paste them from wherever they copied them, and anything that is not an
     * address is dropped rather than handed to the transport.
     *
     * @return array<int, string>
     */
    public static function splitAddresses(?string $addresses): array
    {
        return collect(preg_split('/[,;\s]+/', (string) $addresses, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->values()
            ->all();
    }
}
