<?php

namespace Tests\Feature\Groups;

use App\Models\Actionlog;
use App\Models\Group;
use App\Models\User;
use Tests\TestCase;

class GroupAuditLogTest extends TestCase
{
    public function testCreatingAGroupIsLogged()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('groups.store'), ['name' => 'Audited Group'])
            ->assertStatus(302);

        $group = Group::where('name', 'Audited Group')->sole();

        $this->assertTrue(
            Actionlog::where('item_type', Group::class)
                ->where('item_id', $group->id)
                ->where('action_type', 'create')
                ->exists(),
            'Creating a group should write a create row to the action log.'
        );
    }

    public function testEditingGroupPermissionsIsLoggedWithADiff()
    {
        $group = Group::factory()->create([
            'name' => 'Permission Audit Group',
            'notes' => 'Unchanged note',
            'permissions' => json_encode(['admin' => '0']),
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->put(route('groups.update', ['group' => $group]), [
                'name' => 'Permission Audit Group',
                'notes' => 'Unchanged note',
                'permission' => ['admin' => '1'],
            ])
            ->assertStatus(302);

        $log = Actionlog::where('item_type', Group::class)
            ->where('item_id', $group->id)
            ->where('action_type', 'update')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Editing a group should write an update row to the action log.');

        $meta = json_decode($log->log_meta, true);

        $this->assertArrayHasKey('permissions', $meta);
        $this->assertArrayHasKey('old', $meta['permissions']);
        $this->assertArrayHasKey('new', $meta['permissions']);
        $this->assertNotSame($meta['permissions']['old'], $meta['permissions']['new']);
    }

    public function testRenamingAGroupIsLogged()
    {
        $group = Group::factory()->create([
            'name' => 'Before Rename',
            'notes' => 'Unchanged note',
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->put(route('groups.update', ['group' => $group]), [
                'name' => 'After Rename',
                'notes' => 'Unchanged note',
            ])
            ->assertStatus(302);

        $log = Actionlog::where('item_type', Group::class)
            ->where('item_id', $group->id)
            ->where('action_type', 'update')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);

        $meta = json_decode($log->log_meta, true);

        $this->assertSame('Before Rename', $meta['name']['old']);
        $this->assertSame('After Rename', $meta['name']['new']);
    }

    public function testUnchangedGroupSaveIsNotLogged()
    {
        $group = Group::factory()->create([
            'name' => 'Untouched Group',
            'notes' => 'Untouched note',
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->put(route('groups.update', ['group' => $group]), [
                'name' => 'Untouched Group',
                'notes' => 'Untouched note',
            ])
            ->assertStatus(302);

        $this->assertFalse(
            Actionlog::where('item_type', Group::class)
                ->where('item_id', $group->id)
                ->where('action_type', 'update')
                ->exists(),
            'Saving a group without changing a tracked field should not write a log row.'
        );
    }

    public function testDeletingAGroupIsLogged()
    {
        $group = Group::factory()->create(['name' => 'Doomed Group']);
        $groupId = $group->id;

        $this->actingAs(User::factory()->superuser()->create())
            ->delete(route('groups.destroy', ['group' => $group]))
            ->assertStatus(302);

        $this->assertTrue(
            Actionlog::where('item_type', Group::class)
                ->where('item_id', $groupId)
                ->where('action_type', 'delete')
                ->exists(),
            'Deleting a group should write a delete row to the action log.'
        );
    }
}
