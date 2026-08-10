<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Every permission offered in the group editor has to actually grant
 * something.
 *
 * Laravel denies an ability nobody defined, so a permission added to
 * config/permissions.php without a matching gate renders as a checkbox,
 * saves, reads back as "1" — and grants nothing to anyone but a superuser.
 * Five permissions sat like that for months: the contracts and fleet-health
 * reports, three of the transactions reports, and budget allocations. Every
 * ITS group had the boxes ticked and every one of those pages 403'd.
 */
class PermissionGateCoverageTest extends TestCase
{
    /** @return array<int, string> */
    private function configuredPermissions(): array
    {
        return collect(config('permissions'))
            ->flatten(1)
            ->pluck('permission')
            ->filter()
            ->values()
            ->all();
    }

    public function test_every_configured_permission_has_a_gate()
    {
        $missing = array_values(array_filter(
            $this->configuredPermissions(),
            fn (string $permission) => ! Gate::has($permission),
        ));

        $this->assertSame([], $missing, 'Permissions with no gate — they would render as checkboxes that grant nothing: '.implode(', ', $missing));
    }

    /**
     * @dataProvider permissionsThatUsedToBeDead
     */
    public function test_a_granted_permission_actually_passes_its_gate(string $permission)
    {
        $user = User::factory()->create([
            'permissions' => json_encode([$permission => '1']),
        ]);

        $this->assertTrue($user->can($permission));

        $without = User::factory()->create(['permissions' => json_encode([])]);

        $this->assertFalse($without->can($permission));
    }

    public static function permissionsThatUsedToBeDead(): array
    {
        return [
            'fleet health'          => ['reports.fleet-health.view'],
            'transactions'          => ['reports.transactions.view'],
            'transactions GL'       => ['reports.transactions.gl'],
            'transactions mailroom' => ['reports.transactions.mailroom'],
            'transactions refunds'  => ['reports.transactions.refunds'],
            'transactions overrides' => ['reports.transactions.overrides'],
            'budget allocations'    => ['budget_allocations.manage'],
        ];
    }

    public function test_the_retired_contracts_report_permission_is_gone()
    {
        // /contracts is one page gated by contracts.view. A second key for
        // the reporting half of it only ever produced a half-drawn page.
        $this->assertNotContains('reports.contracts.view', $this->configuredPermissions());
    }
}
