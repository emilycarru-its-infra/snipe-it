<?php

namespace App\Models;

use App\Models\Traits\CompanyableTrait;
use Illuminate\Database\Eloquent\Model;
use Watson\Validating\ValidatingTrait;

class ConsumableAssignment extends Model
{
    use CompanyableTrait;
    use ValidatingTrait;

    protected $table = 'consumables_users';

    public $rules = [
        'assigned_to' => 'required|exists:users,id',
    ];

    public function consumable()
    {
        return $this->belongsTo(Consumable::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'assigned_to');
    }

    /**
     * The user or asset this row was checked out to.
     *
     * `assigned_type` is null on rows created before asset-side checkout
     * existed; those are always user checkouts, so fall back to User.
     */
    public function checkedOutTo()
    {
        return $this->morphTo('assigned', 'assigned_type', 'assigned_to')
            ->withDefault();
    }

    public function adminuser()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
