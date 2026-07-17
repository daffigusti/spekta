<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StackChoice extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['alternatives' => 'array'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
