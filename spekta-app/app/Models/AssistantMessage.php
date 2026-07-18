<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AssistantMessage extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
