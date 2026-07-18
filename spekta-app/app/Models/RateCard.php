<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RateCard extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['roles' => 'array', 'is_default' => 'boolean', 'margin_pct' => 'float'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
