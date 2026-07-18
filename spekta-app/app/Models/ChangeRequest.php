<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ChangeRequest extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'affected_doc_keys' => 'array',
        'delta_md' => 'float',
        'delta_cost' => 'float',
        'decided_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function label(): string
    {
        return 'CR-'.str_pad((string) $this->number, 3, '0', STR_PAD_LEFT);
    }
}
