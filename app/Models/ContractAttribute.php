<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractAttribute extends Model
{
    use HasFactory;

    protected $table = 'contract_attributes';

    protected $fillable = ['contract_id', 'name', 'value'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }
}
