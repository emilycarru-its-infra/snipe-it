<?php

use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentType;
use App\Models\DeploymentWave;
use App\Models\Statuslabel;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time data backfill: migrate devices sitting on the legacy intake
 * "New (*)" Snipe status labels onto the new deployment pipeline. For each
 * Statuslabel named "New (<Stage>)" the inner word maps to a deployment
 * stage by slug (planned/ordered/arrived/inventoried/provisioned); every
 * asset on that status gets a deployment_item on a shared "Device Intake
 * (backfilled)" Ad-hoc wave at the mapped stage.
 *
 * Data-only — changes NO schema (does not touch expected-columns.json).
 * Idempotent: an asset already referenced by any deployment_item is
 * skipped, so it is safe to re-run, and a fresh DB with no New(*) labels
 * simply creates nothing.
 */
class BackfillDeploymentItemsFromNewStatusLabels extends Migration
{
    public function up()
    {
        // Tables must exist (P0 schema). Guard so a partial env is a no-op.
        if (! \Illuminate\Support\Facades\Schema::hasTable('deployment_items')
            || ! \Illuminate\Support\Facades\Schema::hasTable('deployment_stages')) {
            return;
        }

        // Legacy intake statuses, e.g. "New (Planned)" .. "New (Provisioned)".
        $labels = Statuslabel::where('name', 'like', 'New (%)')->get();
        if ($labels->isEmpty()) {
            return;
        }

        $stagesBySlug = DeploymentStage::all()->keyBy('slug');

        // Shared holding wave for these orphaned intake devices.
        $intakeType = DeploymentType::where('slug', 'ad_hoc')->first()
            ?? DeploymentType::first();

        $wave = DeploymentWave::firstOrCreate(
            ['name' => 'Device Intake (backfilled)'],
            [
                'deployment_type_id' => $intakeType?->id,
                'wave_state' => 'planned',
                'fiscal_year' => null,
            ]
        );

        foreach ($labels as $label) {
            // Pull the inner word: "New (Provisioned)" -> "provisioned".
            if (! preg_match('/^New\s*\((.+)\)$/i', trim($label->name), $m)) {
                continue;
            }
            $slug = \Illuminate\Support\Str::slug(trim($m[1]), '_');

            $stage = $stagesBySlug->get($slug);
            if (! $stage) {
                continue;
            }

            Asset::withTrashed()
                ->where('status_id', $label->id)
                ->orderBy('id')
                ->chunkById(200, function ($assets) use ($wave, $stage) {
                    foreach ($assets as $asset) {
                        // Idempotent: skip if any item already references this asset.
                        $exists = DeploymentItem::where('asset_id', $asset->id)
                            ->orWhere('replaces_asset_id', $asset->id)
                            ->exists();
                        if ($exists) {
                            continue;
                        }

                        DeploymentItem::create([
                            'wave_id' => $wave->id,
                            'asset_id' => $asset->id,
                            'model_id' => $asset->model_id,
                            'stage_id' => $stage->id,
                        ]);
                    }
                });
        }
    }

    public function down()
    {
        // Data backfill: remove only the items on the backfilled wave, then
        // the wave itself if it's now empty. Leaves real waves untouched.
        if (! \Illuminate\Support\Facades\Schema::hasTable('deployment_waves')) {
            return;
        }

        $wave = DeploymentWave::where('name', 'Device Intake (backfilled)')->first();
        if (! $wave) {
            return;
        }

        DeploymentItem::where('wave_id', $wave->id)->delete();
        $wave->delete();
    }
}
