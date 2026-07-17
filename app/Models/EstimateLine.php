<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EstimateLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['role_breakdown' => 'array', 'md' => 'float', 'cost' => 'float', 'overridden' => 'boolean'];

    public function estimate()
    {
        return $this->belongsTo(Estimate::class);
    }

    public function structureNode()
    {
        return $this->belongsTo(StructureNode::class);
    }
}
