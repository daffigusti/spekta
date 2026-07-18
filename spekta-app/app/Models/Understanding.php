<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Understanding extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'roles' => 'array',
        'features' => 'array',
        'assumptions' => 'array',
        'contradictions' => 'array',
        'confirmed' => 'boolean',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
