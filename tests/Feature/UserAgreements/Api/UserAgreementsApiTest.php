<?php

namespace Tests\Feature\UserAgreements\Api;

use App\Models\Asset;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use Tests\TestCase;

class UserAgreementsApiTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function newAsset(): Asset
    {
        $status = Statuslabel::factory()->rtd()->create();

        return Asset::factory()->create(['status_id' => $status->id]);
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson(route('api.user-agreements.index'))->assertStatus(401);
    }

    public function test_index_lists_agreements_for_admin(): void
    {
        UserAgreement::create([
            'agreement_type'  => 'pickup',
            'user_id'         => User::factory()->create()->id,
            'lifecycle_stage' => 'quoted',
        ]);
        UserAgreement::create([
            'agreement_type'  => 'purchase',
            'user_id'         => User::factory()->create()->id,
            'lifecycle_stage' => 'quoted',
            'buyout_cost'     => 750,
        ]);

        $this->actingAsForApi($this->superuser())
            ->getJson(route('api.user-agreements.index'))
            ->assertOk()
            ->assertJsonStructure(['total', 'rows' => [['id', 'agreement_type', 'lifecycle_stage', 'available_actions']]]);
    }

    public function test_index_filters_by_agreement_type(): void
    {
        UserAgreement::create([
            'agreement_type'  => 'pickup',
            'user_id'         => User::factory()->create()->id,
            'lifecycle_stage' => 'quoted',
        ]);
        UserAgreement::create([
            'agreement_type'  => 'purchase',
            'user_id'         => User::factory()->create()->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $response = $this->actingAsForApi($this->superuser())
            ->getJson(route('api.user-agreements.index', ['agreement_type' => 'purchase']))
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('purchase', $response->json('rows.0.agreement_type'));
    }

    public function test_index_awaiting_signature_filter(): void
    {
        UserAgreement::create([
            'agreement_type'  => 'pickup',
            'user_id'         => User::factory()->create()->id,
            'lifecycle_stage' => 'paid_off',
        ]);
        UserAgreement::create([
            'agreement_type'  => 'pickup',
            'user_id'         => User::factory()->create()->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $response = $this->actingAsForApi($this->superuser())
            ->getJson(route('api.user-agreements.index', ['awaiting_signature' => 'true']))
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('quoted', $response->json('rows.0.lifecycle_stage'));
    }

    public function test_store_creates_a_pickup_agreement(): void
    {
        $user  = User::factory()->create();
        $asset = $this->newAsset();

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.user-agreements.store'), [
                'agreement_type'     => 'pickup',
                'user_id'            => $user->id,
                'asset_id'           => $asset->id,
                'base_program_price' => 2383.11,
                'device_cost'        => 2700.00,
                'payment_method'     => 'payroll_deduction',
                'lifecycle_stage'    => 'quoted',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.agreement_type', 'pickup');

        $this->assertDatabaseHas('user_agreements', [
            'user_id'        => $user->id,
            'asset_id'       => $asset->id,
            'agreement_type' => 'pickup',
        ]);
    }

    public function test_store_ignores_server_managed_fields(): void
    {
        $user  = User::factory()->create();
        $asset = $this->newAsset();

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.user-agreements.store'), [
                'agreement_type'         => 'pickup',
                'user_id'                => $user->id,
                'asset_id'               => $asset->id,
                'lifecycle_stage'        => 'quoted',
                // These must NOT land — they are server-managed.
                'pdf_path'               => 'forged/path.pdf',
                'signed_pdf_path'        => 'forged/signed.pdf',
                'signed_at'              => '2020-01-01 00:00:00',
                'terms_accepted_at'      => '2020-01-01 00:00:00',
                'checkout_acceptance_id' => 999999,
                'reminders_sent'         => 99,
            ])
            ->assertOk();

        $row = UserAgreement::where('user_id', $user->id)->first();
        $this->assertNull($row->pdf_path);
        $this->assertNull($row->signed_pdf_path);
        $this->assertNull($row->signed_at);
        $this->assertNull($row->terms_accepted_at);
        $this->assertNull($row->checkout_acceptance_id);
    }

    public function test_update_ignores_server_managed_fields(): void
    {
        $agreement = UserAgreement::create([
            'agreement_type'  => 'pickup',
            'user_id'         => User::factory()->create()->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.user-agreements.update', $agreement), [
                'notes'                  => 'real edit',
                'pdf_path'               => 'forged/path.pdf',
                'signed_pdf_path'        => 'forged/signed.pdf',
                'signed_at'              => '2020-01-01 00:00:00',
                'checkout_acceptance_id' => 999999,
            ])
            ->assertOk();

        $row = $agreement->fresh();
        $this->assertSame('real edit', $row->notes);
        $this->assertNull($row->pdf_path);
        $this->assertNull($row->signed_pdf_path);
        $this->assertNull($row->signed_at);
        $this->assertNull($row->checkout_acceptance_id);
    }

    public function test_show_returns_a_single_agreement(): void
    {
        $agreement = UserAgreement::create([
            'agreement_type'  => 'purchase',
            'user_id'         => User::factory()->create()->id,
            'lifecycle_stage' => 'quoted',
            'buyout_cost'     => 800,
        ]);

        $this->actingAsForApi($this->superuser())
            ->getJson(route('api.user-agreements.show', $agreement))
            ->assertOk()
            ->assertJsonPath('id', $agreement->id)
            ->assertJsonPath('agreement_type', 'purchase');
    }

    public function test_update_changes_lifecycle_stage(): void
    {
        $agreement = UserAgreement::create([
            'agreement_type'  => 'pickup',
            'user_id'         => User::factory()->create()->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.user-agreements.update', $agreement), [
                'lifecycle_stage' => 'deployed',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame('deployed', $agreement->fresh()->lifecycle_stage);
    }

    public function test_destroy_soft_deletes_the_agreement(): void
    {
        $agreement = UserAgreement::create([
            'agreement_type'  => 'pickup',
            'user_id'         => User::factory()->create()->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $this->actingAsForApi($this->superuser())
            ->deleteJson(route('api.user-agreements.destroy', $agreement))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSoftDeleted('user_agreements', ['id' => $agreement->id]);
    }

    public function test_send_for_signature_requires_asset_and_user(): void
    {
        $agreement = UserAgreement::create([
            'agreement_type'  => 'pickup',
            'lifecycle_stage' => 'quoted',
        ]);

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.user-agreements.send-for-signature', $agreement))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_send_for_signature_transitions_stage(): void
    {
        $user      = User::factory()->create(['first_name' => 'Eugenia']);
        $asset     = $this->newAsset();
        $agreement = UserAgreement::create([
            'agreement_type'  => 'pickup',
            'user_id'         => $user->id,
            'asset_id'        => $asset->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.user-agreements.send-for-signature', $agreement))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame('agreement_sent', $agreement->fresh()->lifecycle_stage);
        $this->assertNotNull($agreement->fresh()->checkout_acceptance_id);
    }

    public function test_pdf_download_returns_pdf_bytes(): void
    {
        $user      = User::factory()->create(['first_name' => 'Eugenia']);
        $asset     = $this->newAsset();
        $agreement = UserAgreement::create([
            'agreement_type'  => 'pickup',
            'user_id'         => $user->id,
            'asset_id'        => $asset->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $response = $this->actingAsForApi($this->superuser())
            ->get(route('api.user-agreements.pdf', $agreement))
            ->assertOk();

        $this->assertStringStartsWith('application/pdf', $response->headers->get('Content-Type'));
    }
}
