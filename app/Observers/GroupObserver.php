<?php

namespace App\Observers;

use App\Models\Actionlog;
use App\Models\Group;

/**
 * Mirrors UserObserver so group permission edits land in `action_logs` the
 * same way user permission edits do. Without this, changes to a group's
 * permissions JSON are invisible to the audit history — even though those
 * edits affect every member of the group.
 */
class GroupObserver
{
    /**
     * Fires when an existing Group is saved. Logs the diff of allowed fields.
     */
    public function updating(Group $group)
    {
        $allowed_fields = [
            'name',
            'notes',
            'permissions',
        ];

        $changed = [];

        foreach ($group->getRawOriginal() as $key => $value) {
            if (! in_array($key, $allowed_fields)) {
                continue;
            }
            if ($group->getRawOriginal()[$key] != $group->getAttributes()[$key]) {
                $changed[$key]['old'] = $group->getRawOriginal()[$key];
                $changed[$key]['new'] = $group->getAttributes()[$key];
            }
        }

        if (count($changed) > 0) {
            $logAction = new Actionlog;
            $logAction->item_type = Group::class;
            $logAction->item_id = $group->id;
            $logAction->target_type = Group::class;
            $logAction->target_id = $group->id;
            $logAction->created_at = date('Y-m-d H:i:s');
            $logAction->created_by = auth()->id();
            $logAction->log_meta = json_encode($changed);
            $logAction->logaction('update');
        }
    }

    public function created(Group $group)
    {
        $logAction = new Actionlog;
        $logAction->item_type = Group::class;
        $logAction->item_id = $group->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        $logAction->logaction('create');
    }

    public function deleting(Group $group)
    {
        $logAction = new Actionlog;
        $logAction->item_type = Group::class;
        $logAction->item_id = $group->id;
        $logAction->target_type = Group::class;
        $logAction->target_id = $group->id;
        $logAction->created_at = date('Y-m-d H:i:s');
        $logAction->created_by = auth()->id();
        $logAction->logaction('delete');
    }
}
