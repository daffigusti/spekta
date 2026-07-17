<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Baseline extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['snapshot' => 'array', 'approved_at' => 'datetime'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
