<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProjectInput extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['extracted' => 'array'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
