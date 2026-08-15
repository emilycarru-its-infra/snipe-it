<?php

namespace Tests\Feature\Departments;

use App\Models\Department;
use App\Models\User;
use Tests\TestCase;

/**
 * Department-scoped procurement read access: the flag on the department's
 * edit page grants its members the procurement pages by membership alone —
 * nothing hardcoded, view only.
 */
class DepartmentProcurementAccessTest extends TestCase
{
    public function test_members_of_a_flagged_department_can_view_procurement()
    {
        $finance = Department::factory()->create(['name' => 'Finance Test', 'procurement_access' => true]);
        $plain = Department::factory()->create(['name' => 'Plain Test']);

        $member = User::factory()->create(['department_id' => $finance->id, 'activated' => 1]);
        $outsider = User::factory()->create(['department_id' => $plain->id, 'activated' => 1]);
        $nobody = User::factory()->create(['activated' => 1]);

        // The hub redirects to the procurement board; the board and the
        // capital page are the real 200s.
        $this->actingAs($member)->get(route('procurement.index'))->assertRedirect();
        $this->actingAs($member)->get(route('reports.procurement'))->assertOk();
        $this->actingAs($member)->get('/procurement/capital?fiscal_year=FY2026-27')->assertOk();

        $this->actingAs($outsider)->get(route('procurement.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('reports.procurement'))->assertForbidden();

        // View only: the flag never grants edit.
        $this->assertFalse($member->can('procurement.edit'));
    }

    public function test_a_granted_member_gets_the_full_chrome_not_the_end_user_view()
    {
        $finance = Department::factory()->create(['name' => 'Chrome Test', 'procurement_access' => true]);
        $member = User::factory()->create(['department_id' => $finance->id, 'activated' => 1]);

        // Without the grant this user would be an end user (no permissions
        // at all); the department grant must open the full chrome or the
        // pages it unlocks have no door.
        $this->assertFalse($member->isEndUser());

        $this->actingAs($member)->get(route('reports.procurement'))
            ->assertOk()
            ->assertSee(route('reports.procurement.capital-request'), false);
    }

    public function test_the_flag_is_readable_and_writable_over_the_api()
    {
        $admin = User::factory()->superuser()->create();
        $department = Department::factory()->create(['name' => 'API Toggle Test']);

        $this->actingAsForApi($admin)->patchJson(route('api.departments.update', $department->id), [
            'name' => 'API Toggle Test',
            'procurement_access' => true,
        ])->assertOk();
        $this->assertTrue((bool) $department->fresh()->procurement_access);

        $this->actingAsForApi($admin)->getJson(route('api.departments.show', $department->id))
            ->assertOk()
            ->assertJsonPath('procurement_access', true);

        $this->actingAsForApi($admin)->patchJson(route('api.departments.update', $department->id), [
            'name' => 'API Toggle Test',
            'procurement_access' => false,
        ])->assertOk();
        $this->assertFalse((bool) $department->fresh()->procurement_access);
    }

    public function test_a_granted_member_sees_the_full_procurement_menu_and_their_assets_button()
    {
        $finance = Department::factory()->create(['name' => 'Menu Test', 'procurement_access' => true]);
        $member = User::factory()->create(['department_id' => $finance->id, 'activated' => 1]);

        $content = $this->actingAs($member)->get(route('my'))->assertOk()->getContent();

        // The whole menu, not two entries: the paper trail links and the
        // reports, plus the Assets tab pointing at their own equipment.
        foreach ([
            route('orders.index'), route('purchase-orders.index'), route('requisitions.index'),
            route('reports.lessor-breakdown'), route('reports.procurement.capital-request'),
            route('reports.procurement.user-agreement-ledger'), route('my'),
        ] as $link) {
            $this->assertStringContainsString($link, $content, "missing: $link");
        }

        // Read is real: the indexes open...
        $this->actingAs($member)->get(route('requisitions.index'))->assertOk();
        $this->actingAs($member)->get(route('purchase-orders.index'))->assertOk();
        $this->actingAs($member)->get(route('orders.index'))->assertOk();

        // ...and write is not: no drafting, no deciding.
        $this->assertFalse($member->can('create', \App\Models\Requisition::class));
        $this->actingAs($member)->post(route('procurement.queue.decide', \App\Models\StoreOrder::create([
            'user_id' => $member->id, 'status' => 'pending',
        ])->id), ['decision' => 'approved'])->assertForbidden();
    }

    public function test_the_department_form_saves_and_clears_the_flag()
    {
        $admin = User::factory()->superuser()->create();
        $department = Department::factory()->create(['name' => 'Toggle Test']);

        $this->actingAs($admin)->put(route('departments.update', $department->id), [
            'name' => 'Toggle Test',
            'procurement_access' => '1',
        ]);
        $this->assertTrue((bool) $department->fresh()->procurement_access);

        // Unchecked checkbox sends nothing; the controller must clear it.
        $this->actingAs($admin)->put(route('departments.update', $department->id), [
            'name' => 'Toggle Test',
        ]);
        $this->assertFalse((bool) $department->fresh()->procurement_access);
    }
}
