<?php

namespace Tests\Feature\Procurement;

use App\Http\Controllers\ProcurementReportsController;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CapitalRequestLine;
use App\Models\LeaseDecision;
use App\Models\Requisition;
use App\Models\User;
use Tests\TestCase;

/**
 * Every capital-planning action over the API, with the page's gates. The
 * capital request read must be the page's own computation, not a copy, so
 * it is checked against capitalSummary(); the writes are checked against
 * what the page would then read.
 */
class PlanningApiTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_the_capital_request_reads_the_same_numbers_as_the_page()
    {
        CapitalRequestLine::create([
            'fiscal_year' => 'FY2026-27',
            'need' => 'New Ask - Research',
            'description' => 'Workstation',
            'quantity' => 6,
            'unit_cost' => 4885.00,
        ]);

        $payload = $this->actingAsForApi($this->superuser())
            ->getJson(route('api.procurement.capital', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertStatusMessageIs('success')
            ->json('payload');

        $summary = app(ProcurementReportsController::class)->capitalSummary('FY2026-27');

        $this->assertSame('FY2026-27', $payload['fiscal_year']);
        $this->assertEquals($summary['envelope'], $payload['envelope']);
        $this->assertEquals($summary['requested'], $payload['requested']);
        $this->assertEquals($summary['remaining'], $payload['remaining']);
        $this->assertEquals(29310.00, $payload['new_ask_total']);
        $this->assertCount(1, $payload['new_asks']);
        $this->assertSame('Workstation', $payload['new_asks'][0]['description']);
        $this->assertFalse($payload['requisition_backed']);
        $this->assertSame([], $payload['refresh']);
    }

    public function test_reading_the_capital_request_needs_procurement_view()
    {
        $this->actingAsForApi(User::factory()->create())
            ->getJson(route('api.procurement.capital'))
            ->assertForbidden();
    }

    public function test_new_ask_lines_can_be_created_updated_and_deleted()
    {
        $user = $this->superuser();

        $line = $this->actingAsForApi($user)
            ->postJson(route('api.procurement.capital.lines.store'), [
                'fiscal_year' => '2026-27',
                'need' => 'New Ask - Labs',
                'description' => 'Display',
                'quantity' => 2,
                'unit_cost' => 500,
            ])
            ->assertOk()
            ->assertStatusMessageIs('success')
            ->json('payload');

        // Stored under the canonical label the page reads by.
        $this->assertSame('FY2026-27', $line['fiscal_year']);
        $this->assertEquals(1000.00, $line['line_total']);

        $this->actingAsForApi($user)
            ->patchJson(route('api.procurement.capital.lines.update', $line['id']), ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('payload.quantity', 3)
            ->assertJsonPath('payload.description', 'Display');

        $rows = $this->actingAsForApi($user)
            ->getJson(route('api.procurement.capital.lines.index', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->json('payload.rows');
        $this->assertCount(1, $rows);

        $this->actingAsForApi($user)
            ->getJson(route('api.procurement.capital', ['fiscal_year' => 'FY2026-27']))
            ->assertJsonPath('payload.new_ask_total', 1500);

        $this->actingAsForApi($user)
            ->deleteJson(route('api.procurement.capital.lines.destroy', $line['id']))
            ->assertOk();
        $this->assertSame(0, CapitalRequestLine::count());
    }

    public function test_new_ask_lines_are_validated_and_gated_like_the_page()
    {
        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.procurement.capital.lines.store'), ['fiscal_year' => 'FY2026-27'])
            ->assertStatusMessageIs('error');

        $this->actingAsForApi(User::factory()->create())
            ->postJson(route('api.procurement.capital.lines.store'), [
                'fiscal_year' => 'FY2026-27', 'need' => 'x', 'description' => 'y', 'quantity' => 1, 'unit_cost' => 1,
            ])
            ->assertForbidden();
        $this->assertSame(0, CapitalRequestLine::count());
    }

    public function test_a_requisition_can_be_stamped_as_a_capital_request_over_the_api()
    {
        $requisition = Requisition::create(['title' => 'Labs refresh', 'status' => 'requisitioned']);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.requisitions.update', $requisition), ['capital_request_fy' => '2026-27'])
            ->assertOk()
            ->assertJsonPath('payload.capital_request_fy', 'FY2026-27');
        $this->assertSame('FY2026-27', $requisition->refresh()->capital_request_fy);

        // The capital request now reads as that requisition.
        $this->actingAsForApi($this->superuser())
            ->getJson(route('api.procurement.capital', ['fiscal_year' => 'FY2026-27']))
            ->assertJsonPath('payload.requisition_backed', true)
            ->assertJsonPath('payload.requisitions.0.id', $requisition->id);

        // Null unstamps; nonsense is refused.
        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.requisitions.update', $requisition), ['capital_request_fy' => 'next year'])
            ->assertUnprocessable();
        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.requisitions.update', $requisition), ['capital_request_fy' => null])
            ->assertOk();
        $this->assertNull($requisition->refresh()->capital_request_fy);
    }

    public function test_lease_decisions_can_be_logged_changed_and_removed()
    {
        $user = $this->superuser();

        $id = $this->actingAsForApi($user)
            ->postJson(route('api.lease-decisions.store'), [
                'contract_reference' => 'QQ-API-1',
                'decision_type' => 'retain',
                'decision_date' => '2026-09-01',
            ])
            ->assertOk()
            ->assertStatusMessageIs('success')
            ->assertJsonPath('payload.status', 'pending')
            ->json('payload.id');

        $this->actingAsForApi($user)
            ->patchJson(route('api.lease-decisions.update', $id), ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('payload.status', 'approved')
            ->assertJsonPath('payload.decision_type', 'retain');

        $this->actingAsForApi($user)
            ->getJson(route('api.lease-decisions.show', $id))
            ->assertOk()
            ->assertJsonPath('payload.contract_reference', 'QQ-API-1');

        $this->actingAsForApi($user)
            ->postJson(route('api.lease-decisions.store'), ['contract_reference' => 'QQ-API-2', 'decision_type' => 'sell'])
            ->assertStatusMessageIs('error');
        $this->assertDatabaseMissing('lease_decisions', ['contract_reference' => 'QQ-API-2']);

        $this->actingAsForApi($user)
            ->deleteJson(route('api.lease-decisions.destroy', $id))
            ->assertOk();
        $this->assertSoftDeleted('lease_decisions', ['id' => $id]);
    }

    public function test_lease_decision_writes_need_order_permissions()
    {
        $this->actingAsForApi(User::factory()->create())
            ->postJson(route('api.lease-decisions.store'), ['contract_reference' => 'QQ-API-3'])
            ->assertForbidden();
        $this->assertSame(0, LeaseDecision::count());
    }

    public function test_changing_a_model_eol_over_the_api_moves_derived_asset_eol_dates()
    {
        $model = AssetModel::factory()->create(['eol' => 36]);
        $derived = Asset::factory()->create(['model_id' => $model->id, 'purchase_date' => '2024-01-15']);
        $explicit = Asset::factory()->create(['model_id' => $model->id, 'purchase_date' => '2024-01-15']);
        $explicit->forceFill(['asset_eol_date' => '2030-06-30', 'eol_explicit' => true])->saveQuietly();

        $this->assertSame('2027-01-15', substr((string) $derived->refresh()->asset_eol_date, 0, 10));

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.models.update', $model), ['eol' => 48])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertSame('2028-01-15', substr((string) $derived->refresh()->asset_eol_date, 0, 10));
        $this->assertSame('2030-06-30', substr((string) $explicit->refresh()->asset_eol_date, 0, 10));
    }

    public function test_asset_dates_back_fill_in_bulk()
    {
        $model = AssetModel::factory()->create(['eol' => 48]);
        $undated = Asset::factory()->create(['model_id' => $model->id, 'purchase_date' => null]);
        $undated->forceFill(['asset_eol_date' => null, 'eol_explicit' => false])->saveQuietly();
        $moved = Asset::factory()->create(['model_id' => $model->id, 'purchase_date' => '2022-03-01']);
        $pinned = Asset::factory()->create(['model_id' => $model->id, 'purchase_date' => '2023-03-01']);

        $rows = [
            ['id' => $undated->id, 'purchase_date' => '2023-09-01'],
            ['id' => $moved->id, 'purchase_date' => '2022-09-01'],
            ['id' => $pinned->id, 'asset_eol_date' => '2026-12-31'],
        ];

        // A dry run reports and writes nothing.
        $dry = $this->actingAsForApi($this->superuser())
            ->postJson(route('api.assets.dates'), ['dry_run' => true, 'assets' => $rows])
            ->assertOk()
            ->json('payload');
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(3, $dry['changed']);
        $this->assertNull($undated->refresh()->purchase_date);

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.assets.dates'), ['assets' => $rows])
            ->assertOk()
            ->assertStatusMessageIs('success');

        // A purchase date brings a derived EOL with it…
        $undated->refresh();
        $this->assertSame('2023-09-01', $undated->purchase_date->format('Y-m-d'));
        $this->assertSame('2027-09-01', substr((string) $undated->asset_eol_date, 0, 10));
        $this->assertFalse((bool) $undated->eol_explicit);

        // …and moves one that was derived from the old purchase date.
        $moved->refresh();
        $this->assertSame('2026-09-01', substr((string) $moved->asset_eol_date, 0, 10));
        $this->assertFalse((bool) $moved->eol_explicit);

        // An EOL given outright is explicit, and survives a policy change.
        $pinned->refresh();
        $this->assertSame('2026-12-31', substr((string) $pinned->asset_eol_date, 0, 10));
        $this->assertTrue((bool) $pinned->eol_explicit);
    }

    public function test_asset_dates_back_fill_is_all_or_nothing_and_gated()
    {
        $asset = Asset::factory()->create(['purchase_date' => '2022-03-01']);

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.assets.dates'), ['assets' => [
                ['id' => $asset->id, 'purchase_date' => '2021-01-01'],
                ['id' => 999999, 'purchase_date' => '2021-01-01'],
            ]])
            ->assertNotFound();
        $this->assertSame('2022-03-01', $asset->refresh()->purchase_date->format('Y-m-d'));

        $this->actingAsForApi(User::factory()->viewAssets()->create())
            ->postJson(route('api.assets.dates'), ['assets' => [['id' => $asset->id, 'purchase_date' => '2021-01-01']]])
            ->assertForbidden();
    }
}
