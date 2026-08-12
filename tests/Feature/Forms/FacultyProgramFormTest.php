<?php

namespace Tests\Feature\Forms;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\FormAccess;
use App\Services\StoreOrderAssetProvisioner;
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

    public function test_resubmitting_edits_the_open_application_instead_of_duplicating(): void
    {
        $user = $this->facultyUser();

        $payload = [
            'acknowledge_top_up' => '1',
            'payment_method' => 'pay_in_full',
            'buyout_decision' => 'no_prior_laptop',
            'notes' => 'first answers',
            'accept_terms' => '1',
        ];
        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), $payload);

        // Change of heart: installments instead of paying in full. The open
        // agreement is updated in place — never a second record.
        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), array_merge($payload, [
            'payment_method' => 'payroll_deduction',
            'notes' => 'switched to installments',
        ]))->assertRedirect(route('forms.success', 'faculty-program'));

        $agreements = UserAgreement::where('user_id', $user->id)->get();
        $this->assertCount(1, $agreements);
        $this->assertSame('payroll_deduction', $agreements->first()->payment_method);
        $this->assertSame('switched to installments', $agreements->first()->notes);

        // The form now shows their answers, framed as an edit.
        $this->actingAs($user)->get(route('forms.show', 'faculty-program'))
            ->assertOk()
            ->assertSee('Update application', false)
            ->assertDontSee('create an additional record', false);
    }

    public function test_dropping_the_buyout_cancels_the_quoted_purchase(): void
    {
        $user = $this->facultyUser();

        $payload = [
            'acknowledge_top_up' => '1',
            'payment_method' => 'pay_in_full',
            'buyout_decision' => 'yes',
            'buyout_asset_tag' => 'ECI-12345',
            'buyout_serial' => 'XYZ987',
            'accept_terms' => '1',
        ];
        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), $payload);
        $this->assertCount(2, UserAgreement::where('user_id', $user->id)->get());

        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), array_merge($payload, [
            'buyout_decision' => 'no_prior_laptop',
        ]));

        $purchase = UserAgreement::where('user_id', $user->id)->where('agreement_type', 'purchase')->first();
        $this->assertSame('cancelled', $purchase->lifecycle_stage);

        // And back on: the cancelled record stays cancelled, a fresh quoted
        // purchase takes its place.
        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), $payload);
        $purchases = UserAgreement::where('user_id', $user->id)->where('agreement_type', 'purchase')->get();
        $this->assertCount(2, $purchases);
        $this->assertSame(['cancelled', 'quoted'], $purchases->pluck('lifecycle_stage')->sort()->values()->all());
    }

    public function test_editing_regenerates_a_prerendered_pdf(): void
    {
        $user = $this->facultyUser();

        // The PDF renderer needs an asset on the agreement, so this
        // faculty member has a machine and keeps it out of the buyout.
        $category = Category::factory()->create(['name' => 'Laptop']);
        $model = AssetModel::factory()->create(['category_id' => $category->id]);
        Asset::factory()->create([
            'model_id' => $model->id, 'assigned_to' => $user->id,
            'assigned_type' => User::class, 'asset_tag' => 'A00PDF',
        ]);

        $payload = [
            'acknowledge_top_up' => '1',
            'payment_method' => 'pay_in_full',
            'buyout_decision' => 'no',
            'accept_terms' => '1',
        ];
        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), $payload);

        $pickup = UserAgreement::where('user_id', $user->id)->first();
        $path = $pickup->storeUnsignedPdf();
        $this->assertNotNull($path);
        $before = $pickup->fresh()->pdf_generated_at;

        $this->travel(1)->minutes();

        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), array_merge($payload, [
            'payment_method' => 'payroll_deduction',
        ]));

        $after = $pickup->fresh()->pdf_generated_at;
        $this->assertTrue($after->gt($before), 'expected the unsigned PDF to be re-rendered after the edit');
    }

    public function test_processing_applications_can_no_longer_be_edited(): void
    {
        $user = $this->facultyUser();

        $payload = [
            'acknowledge_top_up' => '1',
            'payment_method' => 'pay_in_full',
            'buyout_decision' => 'no_prior_laptop',
            'accept_terms' => '1',
        ];
        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), $payload);

        UserAgreement::where('user_id', $user->id)->update(['lifecycle_stage' => 'agreement_sent']);

        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), array_merge($payload, [
            'payment_method' => 'payroll_deduction',
        ]))->assertRedirect(route('forms.show', 'faculty-program'));

        $this->assertSame('pay_in_full', UserAgreement::where('user_id', $user->id)->first()->payment_method);
    }

    public function test_the_form_lists_every_laptop_and_stores_the_pick(): void
    {
        $user = $this->facultyUser();
        $category = Category::factory()->create(['name' => 'Laptop']);
        $model = AssetModel::factory()->create(['category_id' => $category->id]);

        $newer = Asset::factory()->create([
            'model_id' => $model->id, 'assigned_to' => $user->id,
            'assigned_type' => User::class, 'asset_tag' => 'A00NEWER',
            'last_checkout' => now()->subYear(),
        ]);
        $older = Asset::factory()->create([
            'model_id' => $model->id, 'assigned_to' => $user->id,
            'assigned_type' => User::class, 'asset_tag' => 'A00OLDER',
            'last_checkout' => now()->subYears(4),
        ]);

        // Both machines are offered…
        $this->actingAs($user)->get(route('forms.show', 'faculty-program'))
            ->assertOk()
            ->assertSee('A00NEWER', false)
            ->assertSee('A00OLDER', false)
            ->assertSee('returning_asset_id', false);

        // …and picking the non-default one is what lands on the agreement
        // and what the order pipeline treats as the outgoing machine.
        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), [
            'acknowledge_top_up' => '1',
            'payment_method' => 'pay_in_full',
            'buyout_decision' => 'no',
            'returning_asset_id' => (string) $older->id,
            'accept_terms' => '1',
        ])->assertRedirect(route('forms.success', 'faculty-program'));

        $pickup = UserAgreement::where('user_id', $user->id)->firstWhere('agreement_type', 'pickup');
        $this->assertSame($older->id, $pickup->asset_id);

        $outgoing = app(StoreOrderAssetProvisioner::class)
            ->outgoingMachine(new StoreOrder(['user_id' => $user->id]));
        $this->assertSame($older->id, $outgoing?->id);
        $this->assertNotSame($newer->id, $outgoing?->id);
    }

    public function test_a_foreign_asset_id_falls_back_to_their_own_laptop(): void
    {
        $user = $this->facultyUser();
        $category = Category::factory()->create(['name' => 'Laptop']);
        $model = AssetModel::factory()->create(['category_id' => $category->id]);

        $own = Asset::factory()->create([
            'model_id' => $model->id, 'assigned_to' => $user->id,
            'assigned_type' => User::class, 'last_checkout' => now()->subYears(3),
        ]);
        $strangers = Asset::factory()->create([
            'model_id' => $model->id,
            'assigned_to' => User::factory()->create()->id,
            'assigned_type' => User::class,
        ]);

        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), [
            'acknowledge_top_up' => '1',
            'payment_method' => 'pay_in_full',
            'buyout_decision' => 'no',
            'returning_asset_id' => (string) $strangers->id,
            'accept_terms' => '1',
        ])->assertRedirect(route('forms.success', 'faculty-program'));

        $pickup = UserAgreement::where('user_id', $user->id)->firstWhere('agreement_type', 'pickup');
        $this->assertSame($own->id, $pickup->asset_id);
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

    /**
     * The store gate used to require the application to have been created
     * inside the fiscal year containing the old laptop's lease end. A
     * cohort invited after its lease had already ended therefore submitted
     * into a closed window: the store bounced them back to the form, and
     * re-submitting could not help, because a new row's created_at is
     * always now and the window is always in the past.
     */
    public function test_the_store_opens_after_applying_even_when_the_old_lease_ended_in_a_past_year(): void
    {
        $user = $this->facultyUser();

        $category = Category::factory()->create(['name' => 'Laptop']);
        $model = AssetModel::factory()->create(['category_id' => $category->id]);
        Asset::factory()->create([
            'model_id' => $model->id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'asset_tag' => 'A00WAVE2',
            // A 2021 lease, long expired by the time the invitation lands.
            'lease_end_date' => now()->subYears(2)->format('Y-m-d'),
        ]);

        // Before applying, the store is closed and points at the form.
        $this->actingAs($user)->get(route('store.index'))
            ->assertRedirect(route('forms.show', 'faculty-program'));

        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), [
            'acknowledge_top_up' => '1',
            'payment_method' => 'pay_in_full',
            'buyout_decision' => 'no',
            'accept_terms' => '1',
        ])->assertRedirect(route('forms.success', 'faculty-program'));

        $this->actingAs($user)->get(route('store.index'))->assertOk();
    }

    /**
     * Declining the buyout cancels the purchase agreement — it must not
     * also close the store on the member, whose pickup application is
     * live and whose whole reason for being there is to pick a machine.
     */
    public function test_declining_the_buyout_does_not_close_the_store(): void
    {
        $user = $this->facultyUser();

        $category = Category::factory()->create(['name' => 'Laptop']);
        $model = AssetModel::factory()->create(['category_id' => $category->id]);
        $asset = Asset::factory()->create([
            'model_id' => $model->id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'asset_tag' => 'A00BUYOUT',
        ]);

        // The buyout row the lease-end pipeline creates before anyone is
        // ever invited — nobody asked for it, and declining cancels it.
        UserAgreement::create([
            'agreement_type' => 'purchase',
            'user_id' => $user->id,
            'asset_id' => $asset->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), [
            'acknowledge_top_up' => '1',
            'payment_method' => 'pay_in_full',
            'buyout_decision' => 'no',
            'accept_terms' => '1',
        ])->assertRedirect(route('forms.success', 'faculty-program'));

        $this->assertSame('cancelled', UserAgreement::where('user_id', $user->id)
            ->where('agreement_type', 'purchase')->first()->lifecycle_stage);

        $this->actingAs($user)->get(route('store.index'))->assertOk();
    }
}
