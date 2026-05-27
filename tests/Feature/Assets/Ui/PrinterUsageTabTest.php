<?php

namespace Tests\Feature\Assets\Ui;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CustomFieldset;
use App\Models\Transactions\RawRow;
use App\Models\User;
use App\Services\Transactions\PrinterUsageService;
use Carbon\Carbon;
use Tests\TestCase;

class PrinterUsageTabTest extends TestCase
{
    public function test_printing_tab_hidden_for_non_printer_assets(): void
    {
        $asset = Asset::factory()->create();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.show', $asset))
            ->assertOk()
            ->assertDontSee('id="printing"', false);
    }

    public function test_printing_tab_visible_for_printer_fieldset(): void
    {
        $asset = $this->printerAsset();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.show', $asset))
            ->assertOk()
            ->assertSee('id="printing"', false)
            ->assertSeeText(trans('admin/hardware/printing.tab_label'));
    }

    public function test_printing_tab_visible_for_legacy_printers_and_scanners_fieldset(): void
    {
        $asset = $this->printerAsset('Printers & Scanners');

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.show', $asset))
            ->assertOk()
            ->assertSee('id="printing"', false);
    }

    public function test_printing_tab_renders_no_data_state_when_no_transactions(): void
    {
        $asset = $this->printerAsset();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.show', $asset))
            ->assertOk()
            ->assertSeeText(trans('admin/hardware/printing.no_data'));
    }

    public function test_printing_tab_aggregates_transaction_rows(): void
    {
        $asset = $this->printerAsset();

        RawRow::create([
            'period_year'      => (int) Carbon::now()->year,
            'period_month'     => (int) Carbon::now()->month,
            'source_kind'      => 'papercut.print_logs',
            'printer_asset_id' => $asset->id,
            'row_hash'         => str_pad('a', 64, 'a'),
            'row_data'         => [
                'full name' => 'Alice Example',
                'document'  => 'Syllabus.pdf',
                'total printed pages' => 4,
                'cost'      => '0.40',
                'mapped_gl' => '7-12345-100',
            ],
            'ingested_at'      => Carbon::now()->subDays(2),
        ]);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.show', $asset))
            ->assertOk();

        $response->assertSeeText('Alice Example');
        $response->assertSeeText('Syllabus.pdf');
    }

    public function test_service_helper_distinguishes_printer_assets(): void
    {
        $printer = $this->printerAsset();
        $other = Asset::factory()->create();

        $this->assertTrue(PrinterUsageService::assetIsPrinter($printer));
        $this->assertFalse(PrinterUsageService::assetIsPrinter($other));
        $this->assertFalse(PrinterUsageService::assetIsPrinter(null));
    }

    private function printerAsset(string $fieldsetName = 'Printers'): Asset
    {
        $fieldset = CustomFieldset::factory()->create(['name' => $fieldsetName]);
        $model = AssetModel::factory()->create(['fieldset_id' => $fieldset->id]);

        return Asset::factory()->create(['model_id' => $model->id]);
    }
}
