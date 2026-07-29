<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person allowed to approve or decline store orders.
 *
 * The list is opt-in: while it is empty, the default gate (the same
 * orders permission the rest of procurement uses) decides. The moment
 * anyone is listed, the list is the whole truth — listed users and
 * superusers only.
 */
class StoreApprover extends Model
{
    protected $table = 'store_approvers';

    protected $fillable = ['user_id', 'created_by'];

    /** @return BelongsTo<User, $this> */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** May this user decide store orders? */
    public static function allows(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isSuperUser()) {
            return true;
        }

        if (static::count() === 0) {
            return $user->can('update', Requisition::class);
        }

        return static::where('user_id', $user->id)->exists();
    }
}
