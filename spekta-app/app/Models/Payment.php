<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'credits' => 'float',
        'amount' => 'float',
        'raw_notification' => 'array',
        'paid_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
