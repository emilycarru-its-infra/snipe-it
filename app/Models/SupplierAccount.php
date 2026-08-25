<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a supplier's accounts, as a row rather than a constant.
 *
 * @property int $id
 * @property int|null $supplier_id
 * @property string $key
 * @property string $number
 * @property string $purpose
 * @property string $kind
 * @property string $scope
 * @property string $payee
 * @property string|null $schedule_type
 * @property int $sort
 * @property bool $active
 * @property-read Supplier|null $supplier
 *
 * The account decides which blanket purchase order the supplier places a
 * line against and who is invoiced, so it is a fact about their business
 * that we mirror. Mirrored facts change on their timetable: an account is
 * renumbered, a fifth is opened, one is retired, a second supplier arrives
 * with a grid of their own. None of that should need a deploy, which is why
 * this is a table and why it is keyed to a supplier rather than named after
 * the one reseller we happen to use today.
 */
class SupplierAccount extends Model
{
    protected $table = 'supplier_accounts';

    protected $fillable = [
        'supplier_id',
        'key',
        'number',
        'purpose',
        'kind',
        'scope',
        'payee',
        'schedule_type',
        'sort',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * The shape the SupplierAccounts service reads, so a row and the seed
     * constant are interchangeable.
     *
     * @return array<string, mixed>
     */
    public function toAccountArray(): array
    {
        return [
            'number' => $this->number,
            'kind' => $this->kind,
            'scope' => $this->scope,
            'purpose' => $this->purpose,
            'payee' => $this->payee,
            'schedule' => $this->schedule_type,
        ];
    }
}
