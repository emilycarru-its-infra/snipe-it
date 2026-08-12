<?php

namespace Tests\Feature\Forms;

use App\Mail\FacultyProgramSubmissionMail;
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
use Illuminate\Support\Facades\Mail;
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
     * inside the fiscal year containing the old laptop's lease end, which
     * is unsatisfiable for a cohort invited ahead of its renewal. Wave 2's
     * leases end in October 2027, so the window sat a year in the future
     * and every applicant was bounced back to the form. Re-submitting could
     * not help: created_at is always now.
     *
     * @dataProvider leaseEndOffsets
     */
    public function test_the_store_opens_after_applying_whatever_the_old_lease_end_is(int $yearsFromNow): void
    {
        $user = $this->facultyUser();

        $category = Category::factory()->create(['name' => 'Laptop']);
        $model = AssetModel::factory()->create(['category_id' => $category->id]);
        Asset::factory()->create([
            'model_id' => $model->id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'asset_tag' => 'A00WAVE2',
            'lease_end_date' => now()->addYears($yearsFromNow)->format('Y-m-d'),
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

    /** Lease ending a year out (wave 2), and one already expired. */
    public static function leaseEndOffsets(): array
    {
        return ['renewal ahead' => [1], 'lease already ended' => [-2]];
    }

    /**
     * A submission used to be recorded and announced to nobody, so the
     * first the program heard of an application was usually the applicant
     * asking why nothing had happened.
     */
    public function test_submitting_the_form_mails_the_program(): void
    {
        Mail::fake();
        $user = $this->facultyUser();

        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), [
            'acknowledge_top_up' => '1',
            'payment_method' => 'payroll_deduction',
            'buyout_decision' => 'no_prior_laptop',
            'notes' => 'Do I pick the laptop here or in the store?',
            'accept_terms' => '1',
        ])->assertRedirect(route('forms.success', 'faculty-program'));

        Mail::assertSent(FacultyProgramSubmissionMail::class, function ($mail) use ($user) {
            return $mail->pickup->user_id === $user->id && $mail->updated === false;
        });
    }

    /** An edit is a different mail from a first submission, and says so. */
    public function test_editing_the_form_mails_the_program_as_an_update(): void
    {
        $user = $this->facultyUser();
        $payload = [
            'acknowledge_top_up' => '1',
            'payment_method' => 'pay_in_full',
            'buyout_decision' => 'no_prior_laptop',
            'accept_terms' => '1',
        ];
        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), $payload);

        Mail::fake();
        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), array_merge($payload, [
            'payment_method' => 'payroll_deduction',
        ]));

        Mail::assertSent(FacultyProgramSubmissionMail::class, fn ($mail) => $mail->updated === true);
    }

    /**
     * The template renders, with the two answers whoever works the queue
     * is actually scanning for. Mail::fake() asserts a send but never
     * builds the view, so a broken template would pass every other test
     * here and only fail in front of a real recipient.
     */
    public function test_the_program_email_renders_the_answers(): void
    {
        $user = $this->facultyUser();

        $pickup = UserAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'lifecycle_stage' => 'quoted',
            'payment_method' => 'payroll_deduction',
            'terms_accepted_at' => now(),
            'notes' => 'Do I still select it from the store?',
        ]);

        $html = (new FacultyProgramSubmissionMail($pickup->load('user')))->render();

        $this->assertStringContainsString('Payroll Deduction', $html);
        $this->assertStringContainsString('Do I still select it from the store?', $html);
        $this->assertStringContainsString(
            trans('mail.faculty_program_buyout_none'), $html
        );
    }

    /** A mail transport problem must never cost somebody their submission. */
    public function test_a_failing_program_email_does_not_break_the_submission(): void
    {
        $user = $this->facultyUser();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('relay down'));

        $this->actingAs($user)->post(route('forms.submit', 'faculty-program'), [
            'acknowledge_top_up' => '1',
            'payment_method' => 'pay_in_full',
            'buyout_decision' => 'no_prior_laptop',
            'accept_terms' => '1',
        ])->assertRedirect(route('forms.success', 'faculty-program'));

        $this->assertDatabaseHas('user_agreements', [
            'user_id' => $user->id,
            'agreement_type' => 'pickup',
            'lifecycle_stage' => 'quoted',
        ]);
    }

    /**
     * Faculty are never asked for a GL code — the program pays for their
     * laptop. An optional field reads as a required one to someone who has
     * no answer for it, and on wave 2 it stopped people mid-order.
     */
    public function test_faculty_are_never_asked_for_a_gl_code(): void
    {
        $user = $this->facultyUser();

        UserAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'lifecycle_stage' => 'quoted',
            'terms_accepted_at' => now(),
        ]);

        $this->actingAs($user)->get(route('store.index'))
            ->assertOk()
            ->assertDontSee('st-gl-code', false)
            ->assertDontSee(trans('admin/store/general.gl_code_label'), false);
    }

    /** Everyone else still gets it — their order can be department-funded. */
    public function test_non_faculty_are_still_asked_for_a_gl_code(): void
    {
        $this->actingAs(User::factory()->create())->get(route('store.index'))
            ->assertOk()
            ->assertSee('st-gl-code', false);
    }

    /**
     * A pickup row is not an application. PickupUpgradeAutoCreator writes
     * quoted pickups off a checkout for people who have never seen the
     * form, and those must not open the store — the form is the gate.
     */
    public function test_an_auto_created_pickup_does_not_open_the_store(): void
    {
        $user = $this->facultyUser();

        UserAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $this->actingAs($user)->get(route('store.index'))
            ->assertRedirect(route('forms.show', 'faculty-program'));
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
