<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Estimate extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'rate_card_snapshot' => 'array',
        'team_composition' => 'array',
        'timeline' => 'array',
        'total_md' => 'float',
        'total_cost' => 'float',
        'duration_weeks' => 'float',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function lines()
    {
        return $this->hasMany(EstimateLine::class);
    }
}
