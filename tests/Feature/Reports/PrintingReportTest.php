<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CustomFieldset;
use App\Models\Transactions\RawRow;
use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

class PrintingReportTest extends TestCase
{
    public function test_permission_required_to_view_printing_report(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reports.printing'))
            ->assertForbidden();
    }

    public function test_index_renders_for_users_with_asset_view_permission(): void
    {
        $this->actingAs(User::factory()->viewAssets()->create())
            ->get(route('reports.printing'))
            ->assertOk()
            ->assertSeeText(trans('admin/reports/printing.dashboard_title'));
    }

    public function test_index_lists_printer_assets_with_rolled_up_totals(): void
    {
        $fieldset = CustomFieldset::factory()->create(['name' => 'Printers']);
        $model = AssetModel::factory()->create(['fieldset_id' => $fieldset->id]);
        $printer = Asset::factory()->create([
            'name'     => 'MFP-2nd-Floor',
            'model_id' => $model->id,
        ]);

        Asset::factory()->create();

        $now = Carbon::now();
        RawRow::create([
            'period_year'      => (int) $now->year,
            'period_month'     => (int) $now->month,
            'source_kind'      => 'papercut.print_logs',
            'printer_asset_id' => $printer->id,
            'row_hash'         => str_pad('b', 64, 'b'),
            'row_data'         => [
                'total printed pages' => 12,
                'cost'                => '1.20',
            ],
            'ingested_at'      => $now->copy()->subDay(),
        ]);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.printing'))
            ->assertOk();

        $response->assertSeeText('MFP-2nd-Floor');
        $response->assertSeeText('12');
    }
}
