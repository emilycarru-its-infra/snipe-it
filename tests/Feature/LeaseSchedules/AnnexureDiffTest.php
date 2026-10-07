<?php

namespace Tests\Feature\LeaseSchedules;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\LeaseSchedule;
use App\Models\Statuslabel;
use App\Models\User;
use App\Services\AnnexureParser;
use Tests\TestCase;

class AnnexureDiffTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_diff_route_returns_warning_when_no_upload_attached()
    {
        $schedule = LeaseSchedule::create([
            'schedule_ref' => '100000-099',
            'lifecycle_stage' => 'draft',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('lease-schedules.annexure-diff', $schedule))
            ->assertRedirect(route('lease-schedules.show', $schedule))
            ->assertSessionHas('error', trans('admin/lease-schedules/message.annexure_no_upload'));
    }

    public function test_diff_buckets_serials_against_snipe_assets()
    {
        // The diff finds assets tagged to the schedule via the native
        // lease_contract_id column (F2·2 cutover).
        $contractColumn = 'lease_contract_id';
        $active = Statuslabel::factory()->rtd()->create();

        $matchedAsset = Asset::factory()->create([
            'asset_tag' => 'L0900010',
            'serial' => 'TESTSN000001',
            'status_id' => $active->id,
        ]);
        Asset::query()->whereKey($matchedAsset->id)->update([$contractColumn => '100000-099']);

        // Asset tagged to the schedule but not on the lessor's annexure
        // — should land in missing-in-annexure.
        $extraAsset = Asset::factory()->create([
            'asset_tag' => 'L0900011',
            'serial' => 'EXTRA987XYZ',
            'status_id' => $active->id,
        ]);
        Asset::query()->whereKey($extraAsset->id)->update([$contractColumn => '100000-099']);

        $schedule = LeaseSchedule::create([
            'schedule_ref' => '100000-099',
            'lifecycle_stage' => 'awaiting_signature',
        ]);

        // Stamp an upload log entry; the diff reads the file via the
        // AnnexureParser, which we'll mock at the controller boundary.
        // Actionlog::$fillable doesn't include 'filename'; forceCreate
        // bypasses mass-assignment protection so the stamped row has the
        // file the controller's whereNotNull('filename') query needs.
        Actionlog::forceCreate([
            'item_type' => LeaseSchedule::class,
            'item_id' => $schedule->id,
            'action_type' => 'uploaded',
            'filename' => 'annexure-test.pdf',
        ]);

        // The parser is resolved from the container; bind a fake that
        // returns the annexure's serial list directly.
        $this->app->bind(AnnexureParser::class, function () {
            return new class extends AnnexureParser
            {
                public function serialsFromPdf(string $relativePath): array
                {
                    // TESTSN000001 is on the asset list (matched);
                    // TESTSN0005 isn't in Snipe yet (missing-in-snipe).
                    return ['TESTSN000001', 'TESTSN0005'];
                }
            };
        });

        $response = $this->actingAs($this->superuser())
            ->get(route('lease-schedules.annexure-diff', $schedule));

        $response->assertOk()
            ->assertSee('TESTSN000001')     // matched bucket
            ->assertSee('L0900010')          // matched bucket — asset tag
            ->assertSee('TESTSN0005')       // missing-in-snipe bucket
            ->assertSee('EXTRA987XYZ')      // missing-in-annexure bucket
            ->assertSee('L0900011');         // missing-in-annexure bucket — asset tag
    }
}
