<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\CsiAsset;
use App\Models\CsiInprocessAsset;
use App\Models\CsiInvoice;
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
        'inprocess' => [CsiInprocessAsset::class, ['serial', 'order_number']],
        'invoices' => [CsiInvoice::class, ['csi_invoice_number']],
    ];

    public function snapshot(Request $request): array
    {
        // Reuse the procurement write capability — the same authorization the
        // CDW orders/ingest webhook runs under.
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'entity' => 'required|string|in:leases,schedules,assets,inprocess,invoices',
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

        return Helper::formatStandardApiResponse('success', [
            'entity' => $data['entity'],
            'upserted' => $upserted,
        ], 'CSI '.$data['entity'].' snapshot ingested ('.$upserted.' rows).');
    }
}
