<?php

namespace Tests\Feature\Forms;

use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\FormAccess;
use Tests\TestCase;

class FacultyProgramFormTest extends TestCase
{
    private function facultyUser(): User
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['name' => 'Regular Faculty']);
        $user->groups()->attach($group->id);

        FormEligibility::create(['form_slug' => 'faculty-program', 'group_id' => $group->id]);
        FormAccess::flush();

        return $user;
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get(route('forms.show', 'faculty-program'))->assertStatus(302);
    }

    public function test_non_eligible_user_gets_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('forms.show', 'faculty-program'))
            ->assertStatus(403);
    }

    public function test_eligible_user_sees_the_form(): void
    {
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->get(route('forms.show', 'faculty-program'))
            ->assertOk()
            ->assertSee(trans('admin/forms/faculty-program.section_payment'));
    }

    public function test_submitting_pickup_only_creates_one_quoted_agreement(): void
    {
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->post(route('forms.submit', 'faculty-program'), [
                'acknowledge_top_up' => '1',
                'payment_method' => 'pay_in_full',
                'buyout_decision' => 'no_prior_laptop',
                'notes' => 'no upgrades please',
                'accept_terms' => '1',
            ])
            ->assertRedirect(route('forms.success', 'faculty-program'));

        $this->assertCount(1, UserAgreement::where('user_id', $user->id)->get());

        $pickup = UserAgreement::where('user_id', $user->id)->first();
        $this->assertSame('pickup', $pickup->agreement_type);
        $this->assertSame('quoted', $pickup->lifecycle_stage);
        $this->assertSame('pay_in_full', $pickup->payment_method);
        $this->assertNotNull($pickup->terms_accepted_at);
        $this->assertSame('no upgrades please', $pickup->notes);
    }

    public function test_buyout_yes_also_creates_purchase_agreement(): void
    {
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->post(route('forms.submit', 'faculty-program'), [
                'acknowledge_top_up' => '1',
                'payment_method' => 'payroll_deduction',
                'buyout_decision' => 'yes',
                'buyout_asset_tag' => 'ECI-12345',
                'buyout_serial' => 'XYZ987',
                'accept_terms' => '1',
            ])
            ->assertRedirect(route('forms.success', 'faculty-program'));

        $agreements = UserAgreement::where('user_id', $user->id)->get();
        $this->assertCount(2, $agreements);

        $buyout = $agreements->firstWhere('agreement_type', 'purchase');
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
            ->post(route('forms.submit', 'faculty-program'), [
                'payment_method' => 'pay_in_full',
                'buyout_decision' => 'yes',
                'accept_terms' => '1',
            ])
            ->assertSessionHasErrors('buyout_asset_tag');

        $this->assertCount(0, UserAgreement::where('user_id', $user->id)->get());
    }

    public function test_missing_top_up_acknowledgment_fails_validation(): void
    {
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->post(route('forms.submit', 'faculty-program'), [
                'payment_method' => 'pay_in_full',
                'buyout_decision' => 'no_prior_laptop',
                'accept_terms' => '1',
            ])
            ->assertSessionHasErrors('acknowledge_top_up');

        $this->assertCount(0, UserAgreement::where('user_id', $user->id)->get());
    }

    public function test_missing_terms_acceptance_fails_validation(): void
    {
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->post(route('forms.submit', 'faculty-program'), [
                'payment_method' => 'pay_in_full',
                'buyout_decision' => 'no_prior_laptop',
            ])
            ->assertSessionHasErrors('accept_terms');

        $this->assertCount(0, UserAgreement::where('user_id', $user->id)->get());
    }

    public function test_no_eligibility_rows_means_no_access(): void
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['name' => 'Regular Faculty']);
        $user->groups()->attach($group->id);
        FormAccess::flush();

        $this->actingAs($user)
            ->get(route('forms.show', 'faculty-program'))
            ->assertStatus(403);
    }

    public function test_legacy_user_form_redirects_to_new_route(): void
    {
        $user = $this->facultyUser();

        $this->actingAs($user)
            ->get('/user-form')
            ->assertRedirect(route('forms.show', 'faculty-program'));
    }

    public function test_the_success_page_sends_faculty_to_our_own_store()
    {
        // The last step of the program used to be a link to CDW's hosted
        // eStore, which the 2026-07-29 process change retired — leaving the
        // flow ending on a dead page. It now points at the internal store,
        // in this tab, because it is the next step of the same journey.
        config(['forms.faculty_program.purchase_url' => null]);

        $user = $this->facultyUser();

        $this->actingAs($user)->get(route('forms.success', 'faculty-program'))
            ->assertOk()
            ->assertSee(route('store.index'), false)
            ->assertDontSee('cdw.ca', false)
            // The internal destination drops the external-link affordance;
            // asserted on the icon because the layout carries other
            // target="_blank" links of its own.
            ->assertSee('fa-cart-shopping', false)
            ->assertDontSee('fa-external-link-alt', false);
    }

    public function test_a_configured_vendor_url_still_opens_externally()
    {
        config(['forms.faculty_program.purchase_url' => 'https://example.test/store']);

        $this->actingAs($this->facultyUser())->get(route('forms.success', 'faculty-program'))
            ->assertOk()
            ->assertSee('https://example.test/store', false)
            ->assertSee('fa-external-link-alt', false);
    }
}
