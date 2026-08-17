<?php

namespace Tests\Feature\Security;

use App\Models\Department;
use App\Models\Group;
use App\Models\User;
use App\Services\PageAccessAudit;
use Tests\TestCase;

/**
 * The access audit answers "who can see this page?" by running the page's
 * own gate, so the tests that matter are the ones where a stored list would
 * have lied: a permission reached through a group, a grant that arrives
 * through the org chart rather than a checkbox, and an individual denial
 * that quietly overrides a group grant.
 */
class PageAccessAuditTest extends TestCase
{
    public function test_audit_is_closed_to_everyone_but_superadmins()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('access-audit.show', ['path' => 'procurement']))
            ->assertForbidden();
    }

    public function test_audit_is_open_to_superadmins()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('access-audit.show', ['path' => 'procurement']))
            ->assertOk();
    }

    public function test_the_roster_ships_with_a_filter_wired_to_it()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('access-audit.show', ['path' => 'procurement']))
            ->assertOk()
            ->assertSee('id="access-audit-filter"', false)
            ->assertSee('id="access-audit-people"', false)
            ->assertSee('aria-controls="access-audit-people"', false);
    }

    public function test_only_the_declared_sensitive_areas_are_auditable()
    {
        $this->assertTrue(PageAccessAudit::isAuditable('procurement'));
        $this->assertTrue(PageAccessAudit::isAuditable('deployments/forecast'));
        $this->assertTrue(PageAccessAudit::isAuditable('contracts'));
        $this->assertTrue(PageAccessAudit::isAuditable('reports/transactions/gl-breakdown'));
        $this->assertTrue(PageAccessAudit::isAuditable('reports/printing'));

        $this->assertFalse(PageAccessAudit::isAuditable('hardware'));
        $this->assertFalse(PageAccessAudit::isAuditable('reports/audit'));
        // A prefix must be a path segment, not a string prefix.
        $this->assertFalse(PageAccessAudit::isAuditable('contracts-elsewhere'));
    }

    public function test_it_names_a_person_whose_only_path_is_a_group()
    {
        $group = Group::factory()->create([
            'name' => 'Procurement Readers',
            'permissions' => json_encode(['procurement.view' => '1']),
        ]);

        $member = User::factory()->create();
        $member->groups()->attach($group->id);

        $result = (new PageAccessAudit)->audit('procurement');

        $this->assertContains($member->id, $result['people']->pluck('user.id')->all());
        $this->assertContains('Procurement Readers', $result['groups']->pluck('group.name')->all());

        $sources = $result['people']->firstWhere('user.id', $member->id)['sources'];
        $this->assertSame('group', $sources[0]['type']);
        $this->assertSame('Procurement Readers', $sources[0]['name']);
    }

    public function test_an_individual_denial_beats_the_group_grant()
    {
        $group = Group::factory()->create([
            'permissions' => json_encode(['procurement.view' => '1']),
        ]);

        $denied = User::factory()->create(['permissions' => json_encode(['procurement.view' => '-1'])]);
        $denied->groups()->attach($group->id);

        $result = (new PageAccessAudit)->audit('procurement');

        // The group is still listed — it does grant the page — but the
        // person it would grant it to is not, because the running gate says
        // no. Only asking the real user finds this.
        $this->assertNotContains($denied->id, $result['people']->pluck('user.id')->all());
        $this->assertContains($group->name, $result['groups']->pluck('group.name')->all());
    }

    public function test_it_finds_access_that_arrives_through_the_org_chart()
    {
        $department = Department::factory()->create(['procurement_access' => 1]);
        $member = User::factory()->create(['department_id' => $department->id]);

        $result = (new PageAccessAudit)->audit('procurement');

        $this->assertContains($member->id, $result['people']->pluck('user.id')->all());
        $this->assertContains($department->name, $result['departments']->pluck('department.name')->all());

        $sources = $result['people']->firstWhere('user.id', $member->id)['sources'];
        $this->assertSame('department', $sources[0]['type']);
    }

    public function test_an_individual_grant_is_reported_separately_from_a_group_one()
    {
        $solo = User::factory()->create(['permissions' => json_encode(['procurement.view' => '1'])]);

        $result = (new PageAccessAudit)->audit('procurement');

        $this->assertContains($solo->id, $result['individuals']->pluck('id')->all());
        $this->assertNotContains($solo->id, $result['superusers']->pluck('user.id')->all());
    }

    public function test_superadmins_are_listed_as_superadmins_not_as_overrides()
    {
        $super = User::factory()->superuser()->create();

        $result = (new PageAccessAudit)->audit('contracts');

        $this->assertContains($super->id, $result['superusers']->pluck('user.id')->all());
        $this->assertNotContains($super->id, $result['individuals']->pluck('id')->all());
    }

    /**
     * The transactions module splits into narrower keys — the GL breakdown
     * is a smaller audience than the dashboard. The audit has to report the
     * gate on the URL it was asked about, not the module's broadest one.
     */
    public function test_it_reads_the_gate_off_the_url_it_was_asked_about()
    {
        $dashboard = (new PageAccessAudit)->audit('reports/transactions');
        $breakdown = (new PageAccessAudit)->audit('reports/transactions/gl-breakdown');

        $this->assertSame('reports.transactions.view', $dashboard['abilities'][0]['ability']);
        $this->assertSame('reports.transactions.gl', $breakdown['abilities'][0]['ability']);
    }

    public function test_the_lock_shows_on_sensitive_pages_for_superadmins_only()
    {
        $auditUrl = route('access-audit.show', ['path' => 'contracts']);

        $this->actingAs(User::factory()->superuser()->create())
            ->get('/contracts')
            ->assertOk()
            ->assertSee($auditUrl, false);

        $viewer = User::factory()->create(['permissions' => json_encode(['contracts.view' => '1'])]);

        $this->actingAs($viewer)
            ->get('/contracts')
            ->assertOk()
            ->assertDontSee($auditUrl, false);
    }

    public function test_the_lock_stays_off_pages_that_are_not_sensitive()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get('/hardware')
            ->assertOk()
            ->assertDontSee('access-audit', false);
    }
}
