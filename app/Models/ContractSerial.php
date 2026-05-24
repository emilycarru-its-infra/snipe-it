<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractSerial extends Model
{
    use HasFactory;

    protected $table = 'contract_serials';

    protected $fillable = ['contract_id', 'serial', 'source', 'notes'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    // Returns the matching Snipe-IT hardware Asset for this serial, if
    // one exists. Lets the contracts UI and top-bar search jump straight
    // from a contract row to the asset record.
    public function asset()
    {
        return $this->hasOne(Asset::class, 'serial', 'serial');
    }
}
