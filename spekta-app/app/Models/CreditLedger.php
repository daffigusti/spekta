<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CreditLedger extends Model
{
    use HasUuids;

    protected $table = 'credit_ledger';

    protected $guarded = [];

    protected $casts = ['delta' => 'float', 'expires_at' => 'datetime'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
