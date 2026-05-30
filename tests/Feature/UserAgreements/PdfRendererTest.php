<?php

namespace Tests\Feature\UserAgreements;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\UserAgreements\PdfRenderer;
use Tests\TestCase;

class PdfRendererTest extends TestCase
{
    private function makeAgreement(string $type, array $overrides = []): UserAgreement
    {
        $model = AssetModel::factory()->create(['name' => 'MacBook Pro (14-inch, M5)']);
        $status = Statuslabel::factory()->rtd()->create();
        $asset = Asset::factory()->create([
            'asset_tag' => 'A005341',
            'serial'    => 'HG9FC7K7DJ',
            'model_id'  => $model->id,
            'status_id' => $status->id,
        ]);
        $user = User::factory()->create([
            'first_name' => 'Eugenia',
            'last_name'  => 'Bertulis',
            'email'      => 'ebertulis@ecuad.ca',
        ]);

        return UserAgreement::create(array_merge([
            'user_id'              => $user->id,
            'asset_id'             => $asset->id,
            'agreement_type'       => $type,
            'lifecycle_stage'      => 'quoted',
            'base_program_price'   => 2383.11,
            'device_cost'          => 3457.14,
            'top_up_amount'        => 1074.03,
            'buyout_cost'          => 1153.60,
        ], $overrides));
    }

    public function test_pickup_renders_valid_pdf_with_title(): void
    {
        $agreement = $this->makeAgreement('pickup');

        $bytes = app(PdfRenderer::class)->render($agreement);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    public function test_upgrade_renders_valid_pdf_with_payment_options(): void
    {
        $agreement = $this->makeAgreement('upgrade', ['payment_method' => 'payroll_deduction']);

        $bytes = app(PdfRenderer::class)->render($agreement);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    public function test_purchase_renders_valid_pdf(): void
    {
        $agreement = $this->makeAgreement('purchase', ['payment_method' => 'payroll_deduction']);

        $bytes = app(PdfRenderer::class)->render($agreement);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    public function test_unknown_type_throws(): void
    {
        $agreement = $this->makeAgreement('pickup');
        $agreement->agreement_type = 'mystery';

        $this->expectException(\InvalidArgumentException::class);
        app(PdfRenderer::class)->render($agreement);
    }
}
