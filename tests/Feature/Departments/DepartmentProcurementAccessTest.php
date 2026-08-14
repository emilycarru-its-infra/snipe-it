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
