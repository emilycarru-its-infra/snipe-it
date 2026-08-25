<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One of the vendor's accounts, as a row rather than a constant.
 *
 * The account decides which blanket purchase order the reseller places a
 * line against and who is invoiced, so it is a fact about their business
 * that we mirror. Mirrored facts change on their timetable: an account is
 * renumbered, a fifth is opened, one is retired. None of that should need a
 * deploy, which is why this is a table.
 */
class CdwAccount extends Model
{
    protected $table = 'cdw_accounts';

    protected $fillable = [
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

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * The shape the CdwAccounts service reads, so a row and the seed
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
