<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One deployment item per asset per wave, merged and then enforced.
 *
 * The asset is the unit of work on a wave, but nothing stopped a device
 * from being listed twice: the planning side created a row carrying the
 * replaced device, and claiming the order line later created a second row
 * carrying the line, because that path only ever asked whether the *line*
 * was claimed. The board then showed the same iPad twice, in two different
 * stages, and the wave counted twice as many devices as exist.
 *
 * Merging keeps the earliest row — the plan, with the replaced device and
 * the notes on it — fills its blanks from the rows being dropped, and takes
 * the furthest stage any of them reached: two rows describing one device
 * disagree about where it is, and the one that moved is the one that is
 * true. The unique key then keeps the invariant, with items that have no
 * asset yet (a planned replacement, unordered) exempt, since MySQL does not
 * collide NULLs.
 */
class OneDeploymentItemPerAssetOnAWave extends Migration
{
    /** Fields taken from a dropped twin when the survivor has nothing there. */
    private const MERGEABLE = [
        'replaces_asset_id', 'order_item_id', 'model_id', 'group_label',
        'assigned_user_id', 'assigned_tech_id', 'storage_location_id',
        'target_deploy_date', 'deployed_at', 'notes',
    ];

    public function up()
    {
        if (! Schema::hasTable('deployment_items')) {
            return;
        }

        $this->mergeTwins();

        if (! $this->hasIndex('deployment_items', 'deployment_items_wave_asset_unique')) {
            Schema::table('deployment_items', function (Blueprint $table) {
                $table->unique(['wave_id', 'asset_id'], 'deployment_items_wave_asset_unique');
            });
        }
    }

    public function down()
    {
        if (! Schema::hasTable('deployment_items') || ! $this->hasIndex('deployment_items', 'deployment_items_wave_asset_unique')) {
            return;
        }

        Schema::table('deployment_items', function (Blueprint $table) {
            $table->dropUnique('deployment_items_wave_asset_unique');
        });
    }

    private function mergeTwins(): void
    {
        $groups = DB::table('deployment_items')
            ->select('wave_id', 'asset_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('asset_id')
            ->groupBy('wave_id', 'asset_id')
            ->having('total', '>', 1)
            ->get();

        if ($groups->isEmpty()) {
            return;
        }

        $stageOrder = Schema::hasTable('deployment_stages')
            ? DB::table('deployment_stages')->pluck('sort_order', 'id')
            : collect();

        foreach ($groups as $group) {
            $rows = DB::table('deployment_items')
                ->where('wave_id', $group->wave_id)
                ->where('asset_id', $group->asset_id)
                ->orderBy('id')
                ->get();

            $survivor = $rows->first();
            $update = [];

            foreach (self::MERGEABLE as $field) {
                if (! is_null($survivor->{$field})) {
                    continue;
                }

                $filled = $rows->first(fn ($row) => ! is_null($row->{$field}));
                if ($filled) {
                    $update[$field] = $filled->{$field};
                }
            }

            $furthest = $rows
                ->filter(fn ($row) => $row->stage_id)
                ->sortByDesc(fn ($row) => $stageOrder[$row->stage_id] ?? 0)
                ->first();
            if ($furthest && $furthest->stage_id !== $survivor->stage_id) {
                $update['stage_id'] = $furthest->stage_id;
            }

            if ($update) {
                $update['updated_at'] = now();
                DB::table('deployment_items')->where('id', $survivor->id)->update($update);
            }

            DB::table('deployment_items')
                ->where('wave_id', $group->wave_id)
                ->where('asset_id', $group->asset_id)
                ->where('id', '!=', $survivor->id)
                ->delete();
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->pluck('name')
            ->contains($index);
    }
}
