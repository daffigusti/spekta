<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['brand_colors' => 'array', 'settings' => 'array'];

    public function members()
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'workspace_members')->withPivot('role', 'hide_prices');
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function rateCards()
    {
        return $this->hasMany(RateCard::class);
    }

    public function docTemplates()
    {
        return $this->hasMany(DocTemplate::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function creditLedger()
    {
        return $this->hasMany(CreditLedger::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * BR-03: grant kedaluwarsa tidak dihitung; entri konsumsi selalu dihitung.
     * ponytail: aproksimasi konservatif (clamp 0) — alokasi FIFO konsumsi-per-grant kalau akurasi jadi masalah.
     */
    public function creditBalance(): float
    {
        return max(0.0, (float) $this->hasMany(CreditLedger::class)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->sum('delta'));
    }

    /** BR-01: pemakaian chat AI bulan berjalan vs kuota paket (limit null = unlimited). */
    public function chatQuota(): array
    {
        $plan = $this->subscription?->plan ?? 'free';
        $limit = config("spekta.plans.{$plan}.ai_chats_per_month");
        $used = AssistantMessage::whereIn('project_id', $this->projects()->pluck('id'))
            ->where('role', 'user')->where('created_at', '>=', now()->startOfMonth())->count();

        return ['used' => $used, 'limit' => $limit, 'plan' => config("spekta.plans.{$plan}.label", ucfirst($plan))];
    }
}
