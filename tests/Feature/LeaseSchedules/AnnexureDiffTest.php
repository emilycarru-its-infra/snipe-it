<?php

namespace Tests\Feature\LeaseSchedules;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\CustomField;
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
            'schedule_ref' => '301452-099',
            'lifecycle_stage' => 'draft',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('lease-schedules.annexure-diff', $schedule))
            ->assertRedirect(route('lease-schedules.show', $schedule))
            ->assertSessionHas('error', trans('admin/lease-schedules/message.annexure_no_upload'));
    }

    public function test_diff_buckets_serials_against_snipe_assets()
    {
        // Stand up a Lease Contract ID custom field so the diff can find
        // assets tagged to the schedule. The factory takes care of the
        // db_column generation.
        $contractField = CustomField::factory()->create(['name' => 'Lease Contract ID']);
        $contractColumn = $contractField->db_column;
        $active = Statuslabel::factory()->rtd()->create();

        $matchedAsset = Asset::factory()->create([
            'asset_tag' => 'L009001',
            'serial' => 'C02C80E0M0XV',
            'status_id' => $active->id,
        ]);
        Asset::query()->whereKey($matchedAsset->id)->update([$contractColumn => '301452-099']);

        // Asset tagged to the schedule but not on the lessor's annexure
        // — should land in missing-in-annexure.
        $extraAsset = Asset::factory()->create([
            'asset_tag' => 'L009002',
            'serial' => 'EXTRA987XYZ',
            'status_id' => $active->id,
        ]);
        Asset::query()->whereKey($extraAsset->id)->update([$contractColumn => '301452-099']);

        $schedule = LeaseSchedule::create([
            'schedule_ref' => '301452-099',
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
                    // C02C80E0M0XV is on the asset list (matched);
                    // NEWXYZ1234 isn't in Snipe yet (missing-in-snipe).
                    return ['C02C80E0M0XV', 'NEWXYZ1234'];
                }
            };
        });

        $response = $this->actingAs($this->superuser())
            ->get(route('lease-schedules.annexure-diff', $schedule));

        $response->assertOk()
            ->assertSee('C02C80E0M0XV')     // matched bucket
            ->assertSee('L009001')          // matched bucket — asset tag
            ->assertSee('NEWXYZ1234')       // missing-in-snipe bucket
            ->assertSee('EXTRA987XYZ')      // missing-in-annexure bucket
            ->assertSee('L009002');         // missing-in-annexure bucket — asset tag
    }
}
