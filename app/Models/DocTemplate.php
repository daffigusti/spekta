<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocTemplate extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['config' => 'array'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
