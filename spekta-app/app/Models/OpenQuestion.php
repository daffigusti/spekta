<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OpenQuestion extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['answered_at' => 'datetime'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
