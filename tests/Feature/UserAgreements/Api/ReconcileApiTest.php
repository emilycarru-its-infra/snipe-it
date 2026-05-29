<?php

namespace Tests\Feature\UserAgreements\Api;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\FormAccess;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReconcileApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('forms.pickup_auto_create.base_program_price', 2383.11);
        config()->set('forms.purchase_auto_create.lease_end_status_labels', ['Active (Lease End)']);
    }

    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function facultyUser(): User
    {
        $user  = User::factory()->create();
        $group = Group::factory()->create(['name' => 'Regular Faculty']);
        $user->groups()->attach($group->id);
        FormEligibility::create(['form_slug' => 'faculty-program', 'group_id' => $group->id]);
        FormAccess::flush();
        return $user;
    }

    private function rtdStatus(): Statuslabel
    {
        return Statuslabel::factory()->rtd()->create();
    }

    private function assignedAsset(User $user, ?float $cost = null): Asset
    {
        return Asset::factory()->create([
            'status_id'     => $this->rtdStatus()->id,
            'assigned_to'   => $user->id,
            'assigned_type' => User::class,
            'purchase_cost' => $cost,
        ])->fresh();
    }

    public function test_reconcile_requires_auth(): void
    {
        $this->postJson(route('api.user-agreements.reconcile'))->assertStatus(401);
    }

    public function test_dry_run_does_not_write(): void
    {
        $user = $this->facultyUser();
        $this->assignedAsset($user, 3000.00);

        $response = $this->actingAsForApi($this->superuser())
            ->postJson(route('api.user-agreements.reconcile', ['user_id' => $user->id, 'dry_run' => 'true']))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.dry_run', true);

        $this->assertSame(0, UserAgreement::count());
        $this->assertGreaterThanOrEqual(1, $response->json('payload.totals.pickup'));
        $this->assertGreaterThanOrEqual(1, $response->json('payload.totals.upgrade'));
    }

    public function test_runs_for_single_user_and_creates_rows(): void
    {
        $user  = $this->facultyUser();
        $asset = $this->assignedAsset($user, 3000.00);

        $response = $this->actingAsForApi($this->superuser())
            ->postJson(route('api.user-agreements.reconcile', ['user_id' => $user->id]))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.dry_run', false);

        $this->assertSame(2, UserAgreement::where('user_id', $user->id)->count());
        $this->assertSame(1, $response->json('payload.totals.pickup'));
        $this->assertSame(1, $response->json('payload.totals.upgrade'));
    }

    public function test_returns_404_for_unknown_user(): void
    {
        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.user-agreements.reconcile', ['user_id' => 999999]))
            ->assertStatus(404)
            ->assertJsonPath('status', 'error');
    }

    public function test_full_sweep_creates_rows_for_each_faculty_user(): void
    {
        $a = $this->facultyUser();
        $b = $this->facultyUser();
        $this->assignedAsset($a, 3000.00);
        $this->assignedAsset($b, 2200.00);

        $response = $this->actingAsForApi($this->superuser())
            ->postJson(route('api.user-agreements.reconcile'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        // a → pickup + upgrade ; b → pickup only
        $this->assertSame(2, $response->json('payload.totals.pickup'));
        $this->assertSame(1, $response->json('payload.totals.upgrade'));
        $this->assertSame(2, UserAgreement::where('user_id', $a->id)->count());
        $this->assertSame(1, UserAgreement::where('user_id', $b->id)->count());
    }
}
