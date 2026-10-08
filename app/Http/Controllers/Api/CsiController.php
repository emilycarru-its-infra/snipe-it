<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\CsiAsset;
use App\Models\CsiInprocessAsset;
use App\Models\CsiInvoice;
use App\Models\CsiInvoiceAsset;
use App\Models\CsiLease;
use App\Models\CsiSchedule;
use App\Models\Order;
use Illuminate\Http\Request;

class CsiController extends Controller
{
    /**
     * Per-entity mirror config: ingest key => [model, natural-key columns].
     * The csi-poller function fetches each CSI endpoint, normalizes the
     * fields (notably in-process `SerialNumber` -> `serial`), and POSTs a
     * batch here; we upsert by the natural key and stamp last_seen_at so the
     * reconciliation engine can spot rows CSI no longer returns.
     */
    private const ENTITIES = [
        'leases' => [CsiLease::class, ['lease_number']],
        'schedules' => [CsiSchedule::class, ['schedule_name']],
        'assets' => [CsiAsset::class, ['serial', 'schedule_name']],
        'inprocess' => [CsiInprocessAsset::class, ['serial']],
        'invoices' => [CsiInvoice::class, ['csi_invoice_number']],
        'invoice_assets' => [CsiInvoiceAsset::class, ['csi_invoice_number', 'csi_asset_id', 'serial']],
    ];

    public function snapshot(Request $request): array
    {
        // Reuse the procurement write capability — the same authorization the
        // CDW orders/ingest webhook runs under.
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'entity' => 'required|string|in:'.implode(',', array_keys(self::ENTITIES)),
            'items' => 'present|array',
            'items.*' => 'array',
        ]);

        [$modelClass, $keys] = self::ENTITIES[$data['entity']];
        $fillable = (new $modelClass)->getFillable();
        $now = now();

        $upserted = 0;
        foreach ($data['items'] as $item) {
            $keyVals = [];
            foreach ($keys as $k) {
                $keyVals[$k] = $item[$k] ?? null;
            }

            $attrs = ['last_seen_at' => $now];
            foreach ($fillable as $field) {
                if ($field === 'last_seen_at') {
                    continue;
                }
                if (array_key_exists($field, $item)) {
                    $attrs[$field] = $item[$field];
                }
            }

            $modelClass::updateOrCreate($keyVals, $attrs);
            $upserted++;
        }

        // Either side of the hand-off can arrive first in a sync — schedules
        // post before in-process — so check after both.
        $purged = in_array($data['entity'], ['schedules', 'inprocess'], true)
            ? $this->purgeActivatedInprocess()
            : 0;

        return Helper::formatStandardApiResponse('success', [
            'entity' => $data['entity'],
            'upserted' => $upserted,
            'purged_inprocess' => $purged,
        ], 'CSI '.$data['entity'].' snapshot ingested ('.$upserted.' rows).');
    }

    /**
     * Drop in-process rows for schedules that have gone active. CSI publishes
     * a schedule only once it commences, and by then its equipment is on the
     * accepted feed, so an in-process row left behind is stale: it stops
     * being returned but would otherwise sit in the mirror indefinitely.
     * Reconciliation already suppresses accepted serials, so this is
     * housekeeping rather than a correction to any count.
     */
    private function purgeActivatedInprocess(): int
    {
        $active = CsiSchedule::query()
            ->where(fn ($q) => $q->whereNull('term_start_date')->orWhere('term_start_date', '<=', now()->toDateString()))
            ->pluck('schedule_name');

        if ($active->isEmpty()) {
            return 0;
        }

        return CsiInprocessAsset::whereIn('schedule_name', $active)->delete();
    }
}
