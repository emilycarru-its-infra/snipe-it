<?php

namespace Tests\Feature\Users\Api;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\User;
use App\Models\UserAgreement;
use Tests\TestCase;

class UserEulasAgreementTest extends TestCase
{
    private function signedEulaFor(User $person, string $type, string $stage, string $file): void
    {
        $asset = Asset::factory()->create();

        (new UserAgreement)->forceFill([
            'agreement_type' => $type,
            'lifecycle_stage' => $stage,
            'user_id' => $person->id,
            'asset_id' => $asset->id,
            'signed_at' => now(),
            'signed_pdf_path' => $file,
        ])->saveQuietly();

        $log = new Actionlog;
        $log->forceFill([
            'target_id' => $person->id,
            'target_type' => User::class,
            'item_id' => $asset->id,
            'item_type' => Asset::class,
            'action_type' => 'accepted',
            'filename' => $file,
            'accept_signature' => 'sig-'.$file.'.png',
            'created_by' => $person->id,
        ])->save();
    }

    public function test_user_eulas_include_agreement_type_and_stage_for_permitted_callers()
    {
        $person = User::factory()->create();
        $this->signedEulaFor($person, 'pickup', 'agreement_signed', 'one.pdf');
        $this->signedEulaFor($person, 'upgrade', 'agreement_signed', 'two.pdf');

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.user.eulas', $person))
            ->assertOk()
            ->assertJsonFragment(['key' => 'pickup', 'name' => 'New Laptop Pickup'])
            ->assertJsonFragment(['key' => 'upgrade', 'name' => 'Paid Upgrade'])
            ->assertJsonFragment(['key' => 'agreement_signed', 'name' => 'Signed']);
    }

    public function test_user_eulas_omit_agreement_without_files_and_orders_permission()
    {
        $person = User::factory()->create();
        $this->signedEulaFor($person, 'pickup', 'agreement_signed', 'one.pdf');

        $this->actingAsForApi(User::factory()->viewUsers()->create())
            ->getJson(route('api.user.eulas', $person))
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonMissing(['key' => 'pickup'])
            ->assertJsonMissingPath('rows.0.agreement');
    }

    public function test_account_eulas_include_agreement_only_for_permitted_callers()
    {
        $admin = User::factory()->superuser()->create();
        $this->signedEulaFor($admin, 'purchase', 'agreement_signed', 'mine.pdf');

        $this->actingAsForApi($admin)
            ->getJson(route('api.self.eulas'))
            ->assertOk()
            ->assertJsonFragment(['key' => 'purchase', 'name' => 'Purchase']);

        $plain = User::factory()->create();
        $this->signedEulaFor($plain, 'purchase', 'agreement_signed', 'plain.pdf');

        $this->actingAsForApi($plain)
            ->getJson(route('api.self.eulas'))
            ->assertOk()
            ->assertJsonMissingPath('rows.0.agreement');
    }
}
