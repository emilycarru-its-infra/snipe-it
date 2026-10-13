<?php

namespace App\Services;

use App\Models\StoreOrder;

/**
 * Recording what the vendor quoted back for a store order, and confirming
 * it — the moment the order is actually placed.
 *
 * Shared by the queue page and the API so the two cannot disagree about the
 * rules: no quote before the request has gone out, the first figure stamps
 * when the quote arrived, and a confirm without a quote on file is allowed
 * because some orders the vendor simply places.
 */
class StoreOrderQuote
{
    /**
     * @param  array<string, mixed>  $data  quote_number, quote_total, quote_expires_at; blanks are ignored
     * @return array{recorded: bool, error: ?string}
     */
    public function record(StoreOrder $order, array $data, bool $confirm = false): array
    {
        if ($order->vendor_sent_at === null) {
            return ['recorded' => false, 'error' => trans('admin/store/general.quote_wrong_state')];
        }

        $updates = [];

        foreach (['quote_number', 'quote_total', 'quote_expires_at'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $updates[$field] = $data[$field];
            }
        }

        if ($updates !== [] && $order->quote_received_at === null) {
            $updates['quote_received_at'] = now();
        }

        if ($confirm) {
            $updates['quote_received_at'] = $order->quote_received_at ?? now();
            $updates['confirmed_at'] = $order->confirmed_at ?? now();
        }

        if ($updates === []) {
            return ['recorded' => false, 'error' => null];
        }

        $order->update($updates);

        return ['recorded' => true, 'error' => null];
    }
}
