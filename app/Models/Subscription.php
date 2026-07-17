<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    /** BR-05: aktif → grace 7 hari setelah periode habis → read-only (data tetap, export tetap bisa). */
    public function effectiveStatus(): string
    {
        if ($this->plan === 'free' || $this->period_end === null || $this->period_end->isFuture()) {
            return 'active';
        }

        return $this->period_end->addDays(7)->isFuture() ? 'grace' : 'readonly';
    }
}
