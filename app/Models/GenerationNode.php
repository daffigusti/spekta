<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GenerationNode extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['depends_on' => 'array'];

    public function run()
    {
        return $this->belongsTo(GenerationRun::class, 'run_id');
    }
}
