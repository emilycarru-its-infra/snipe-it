<?php

namespace Tests\Feature\UserForm;

use App\Models\UserAgreement;
use App\Models\Group;
use App\Models\User;
use Tests\TestCase;

class UserFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('user-form.group', 'Regular Faculty');
        config()->set('user-form.external_purchase_url', 'https://example.test/estore');
    }

    private function facultyUser(): User
    {
        $user  = User::factory()->create();
        $group = Group::factory()->create(['name' => 'Regular Faculty']);
        $user->groups()->attach($group->id);

        return $user;
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('user-form.show'))->assertRedirect(route('login'));
    }

    public function test_non_eligible_user_gets_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('user-form.show'))
            ->assertStatus(403);
    }

    public function test_eligible_user_sees_the_form(): void
    {
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->get(route('user-form.show'))
            ->assertOk()
            ->assertSee(trans('admin/user-form/general.section_payment'));
    }

    public function test_submitting_pickup_only_creates_one_quoted_agreement(): void
    {
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->post(route('user-form.submit'), [
                'payment_method'  => 'pay_in_full',
                'buyout_decision' => 'no_prior_laptop',
                'notes'           => 'no upgrades please',
                'accept_terms'    => '1',
            ])
            ->assertRedirect(route('user-form.success'));

        $this->assertCount(1, UserAgreement::where('user_id', $user->id)->get());

        $pickup = UserAgreement::where('user_id', $user->id)->first();
        $this->assertSame('pickup', $pickup->agreement_type);
        $this->assertSame('quoted', $pickup->lifecycle_stage);
        $this->assertSame('pay_in_full', $pickup->payment_method);
        $this->assertNotNull($pickup->terms_accepted_at);
        $this->assertSame('no upgrades please', $pickup->notes);
    }

    public function test_buyout_yes_also_creates_lease_end_purchase_agreement(): void
    {
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->post(route('user-form.submit'), [
                'payment_method'   => 'payroll_deduction',
                'buyout_decision'  => 'yes',
                'buyout_asset_tag' => 'ECI-12345',
                'buyout_serial'    => 'XYZ987',
                'accept_terms'     => '1',
            ])
            ->assertRedirect(route('user-form.success'));

        $agreements = UserAgreement::where('user_id', $user->id)->get();
        $this->assertCount(2, $agreements);

        $buyout = $agreements->firstWhere('agreement_type', 'lease_end_purchase');
        $this->assertNotNull($buyout);
        $this->assertSame('quoted', $buyout->lifecycle_stage);
        $this->assertSame('ECI-12345', $buyout->old_asset_tag);
        $this->assertSame('XYZ987', $buyout->old_serial);
        $this->assertNotNull($buyout->terms_accepted_at);
    }

    public function test_buyout_yes_without_asset_tag_fails_validation(): void
    {
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->post(route('user-form.submit'), [
                'payment_method'  => 'pay_in_full',
                'buyout_decision' => 'yes',
                'accept_terms'    => '1',
            ])
            ->assertSessionHasErrors('buyout_asset_tag');

        $this->assertCount(0, UserAgreement::where('user_id', $user->id)->get());
    }

    public function test_missing_terms_acceptance_fails_validation(): void
    {
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->post(route('user-form.submit'), [
                'payment_method'  => 'pay_in_full',
                'buyout_decision' => 'no_prior_laptop',
            ])
            ->assertSessionHasErrors('accept_terms');

        $this->assertCount(0, UserAgreement::where('user_id', $user->id)->get());
    }

    public function test_disabling_group_in_config_disables_form_globally(): void
    {
        config()->set('user-form.group', null);
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->get(route('user-form.show'))
            ->assertStatus(403);
    }
}
