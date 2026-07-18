<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentVersion extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['generated_meta' => 'array'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
