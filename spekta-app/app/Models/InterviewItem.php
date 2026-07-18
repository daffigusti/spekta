<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InterviewItem extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['options' => 'array', 'skipped' => 'boolean'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
