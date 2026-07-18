<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocTemplate extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'doc_kinds' => 'array',
        'config' => 'array',
        'is_default' => 'boolean',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
