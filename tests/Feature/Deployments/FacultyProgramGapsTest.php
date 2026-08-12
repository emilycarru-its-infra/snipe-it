<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentWave;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\Deployments\WaveMembership;
use App\Services\UserAgreements\IntentReconciler;
use Tests\TestCase;

/**
 * The four things the Faculty Laptop Program was keeping in somebody's head.
 *
 * Each of these was previously answered by reading two screens and comparing
 * names, in March, a year after the decision was made.
 */
class FacultyProgramGapsTest extends TestCase
{
    private function wave(array $overrides = []): DeploymentWave
    {
        return DeploymentWave::create(array_merge([
            'name' => 'Faculty Laptop Program refresh FY2026-27',
            'slug' => 'flp-'.uniqid(),
            'fiscal_year' => 'FY2026-27',
            'wave_state' => 'planned',
            'announced_at' => now(),
        ], $overrides));
    }

    private function heldAsset(User $user, ?string $leaseEnd = '2026-12-31', ?string $eol = null): Asset
    {
        $asset = Asset::factory()->create(['lease_end_date' => $leaseEnd]);

        // EOL is stamped after create: the factory sets its own in an
        // afterMaking hook, which would overwrite anything passed in.
        $asset->forceFill([
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'asset_eol_date' => $eol,
        ])->saveQuietly();

        return $asset->refresh();
    }

    /**
     * An order knows which invitation it answers, so "who from wave 2 has
     * ordered" is a query rather than a name comparison.
     */
    public function test_a_faculty_order_is_stamped_with_the_wave_that_invited_it()
    {
        $wave = $this->wave();
        $faculty = User::factory()->create();
        $asset = $this->heldAsset($faculty);
        DeploymentItem::create(['wave_id' => $wave->id, 'replaces_asset_id' => $asset->id]);

        $this->assertSame($wave->id, (new WaveMembership)->waveFor($faculty)?->id);
    }

    /** A wave nobody was told about is not the invitation an order answers. */
    public function test_an_unannounced_wave_is_not_the_invitation()
    {
        $wave = $this->wave(['announced_at' => null]);
        $faculty = User::factory()->create();
        $asset = $this->heldAsset($faculty);
        DeploymentItem::create(['wave_id' => $wave->id, 'replaces_asset_id' => $asset->id]);

        $this->assertNull((new WaveMembership)->waveFor($faculty));
    }

    /**
     * Due means either date: the lease ending, or the machine reaching end of
     * life. Reading lease end alone flagged all twenty faculty in a wave where
     * every one was correctly included — a five-year lease-to-own refreshed on a
     * four-year cycle reaches end of life a year before the lease ends, which is
     * the whole reason the refresh happens when it does.
     *
     * Somebody genuinely not due is usually a deliberate exception, so it warns
     * rather than blocks — but it stops being invisible.
     */
    public function test_a_wave_member_not_due_by_either_date_is_flagged_but_allowed()
    {
        $wave = $this->wave();

        $ending = User::factory()->create();
        DeploymentItem::create(['wave_id' => $wave->id, 'replaces_asset_id' => $this->heldAsset($ending, '2026-12-31')->id]);

        $notEnding = User::factory()->create();
        DeploymentItem::create(['wave_id' => $wave->id, 'replaces_asset_id' => $this->heldAsset($notEnding, '2031-12-31')->id]);

        $noDate = User::factory()->create();
        DeploymentItem::create(['wave_id' => $wave->id, 'replaces_asset_id' => $this->heldAsset($noDate, null)->id]);

        // The ordinary faculty case, and the one that was wrongly flagged: a
        // five-year lease refreshed on a four-year cycle. The lease runs past the
        // window; end of life does not.
        $eolFirst = User::factory()->create();
        DeploymentItem::create(['wave_id' => $wave->id,
            'replaces_asset_id' => $this->heldAsset($eolFirst, '2031-12-31', '2026-09-01')->id]);

        $flagged = (new WaveMembership)->ineligible($wave->fresh());

        $this->assertCount(2, $flagged);
        $this->assertEqualsCanonicalizing(
            [$notEnding->id, $noDate->id],
            $flagged->pluck('user.id')->all()
        );
        $this->assertSame('not_due', $flagged->firstWhere('user.id', $notEnding->id)['reason']);
        $this->assertSame('no_dates', $flagged->firstWhere('user.id', $noDate->id)['reason']);

        // The lease-to-own case is NOT flagged: end of life is what makes them due.
        $this->assertNotContains($eolFirst->id, $flagged->pluck('user.id')->all());

        // Flagged, not excluded: the announcement still reaches everybody.
        $this->assertCount(4, (new \App\Services\Deployments\WaveAnnouncer)->recipients($wave->fresh()));
    }

    /**
     * "Said they would return it, still holding it in March" is the expensive
     * mismatch: CSI invoices us for a device that never came back.
     */
    public function test_a_stated_return_that_is_still_held_is_a_mismatch()
    {
        $faculty = User::factory()->create();
        $asset = $this->heldAsset($faculty);

        $agreement = UserAgreement::create([
            'agreement_type' => 'pickup',
            'stated_intent' => 'return',
            'user_id' => $faculty->id,
            'asset_id' => $asset->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $row = (new IntentReconciler)->describe($agreement->fresh());

        $this->assertSame('return', $row['intent']);
        $this->assertSame('still_held', $row['actual']);
        $this->assertFalse($row['matches']);
        $this->assertNotSame('', $row['note']);
        $this->assertCount(1, (new IntentReconciler)->mismatches());
    }

    /** And once the device has left them, the same answer agrees. */
    public function test_a_stated_return_that_happened_matches()
    {
        $faculty = User::factory()->create();
        $asset = Asset::factory()->create(['lease_end_date' => '2026-12-31']);

        $agreement = UserAgreement::create([
            'agreement_type' => 'pickup',
            'stated_intent' => 'return',
            'user_id' => $faculty->id,
            'asset_id' => $asset->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $row = (new IntentReconciler)->describe($agreement->fresh());

        $this->assertSame('returned', $row['actual']);
        $this->assertTrue($row['matches']);
        $this->assertCount(0, (new IntentReconciler)->mismatches());
    }

    /**
     * A buyout is only reconciled when the paperwork is signed. An agreement
     * sitting at quoted with the device still in their hands is the case where
     * equipment gets kept and nobody is ever charged.
     */
    public function test_a_buyout_is_a_mismatch_until_the_paperwork_is_signed()
    {
        $faculty = User::factory()->create();
        $asset = $this->heldAsset($faculty);

        $agreement = UserAgreement::create([
            'agreement_type' => 'purchase',
            'stated_intent' => 'buyout',
            'user_id' => $faculty->id,
            'asset_id' => $asset->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $this->assertFalse((new IntentReconciler)->describe($agreement->fresh())['matches']);

        $agreement->update(['lifecycle_stage' => 'agreement_signed']);
        $this->assertTrue((new IntentReconciler)->describe($agreement->fresh())['matches']);
    }

    /** The wave page answers all of it in one place. */
    public function test_the_wave_page_shows_who_ordered_and_what_they_said()
    {
        $wave = $this->wave();
        $faculty = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Faculty']);
        $asset = $this->heldAsset($faculty);
        DeploymentItem::create(['wave_id' => $wave->id, 'replaces_asset_id' => $asset->id]);

        UserAgreement::create([
            'agreement_type' => 'pickup',
            'stated_intent' => 'return',
            'user_id' => $faculty->id,
            'asset_id' => $asset->id,
            'lifecycle_stage' => 'quoted',
        ]);

        StoreOrder::create([
            'user_id' => $faculty->id,
            'status' => 'pending',
            'program' => 'faculty',
            'deployment_wave_id' => $wave->id,
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('deployment-waves.show', $wave))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.roster_person'))
            ->assertSee(trans('admin/deployments/general.usage_assigned'))
            ->assertSee('Ada')
            ->assertSee(trans('admin/user-agreements/general.intent_return'))
            ->assertSee(trans('admin/user-agreements/general.intent_actual_still_held'))
            ->assertSee('1 of 1 have ordered');
    }
}
