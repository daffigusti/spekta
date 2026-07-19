<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GenerationRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime', 'repaired_at' => 'datetime', 'meta' => 'array'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function nodes()
    {
        // Tanpa ORDER BY, Postgres mengembalikan tuple urut update terakhir (MVCC) —
        // panel generate jadi acak. id = UUIDv7 ordered → urutan pembuatan = urutan topo.
        return $this->hasMany(GenerationNode::class, 'run_id')->orderBy('id');
    }
}
