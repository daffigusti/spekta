<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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

    /**
     * Template default workspace — dibuat sekali saat pertama diakses (onboarding).
     * Set dokumen lengkap (complexity 3), bahasa ikut locale workspace, proposal white-label.
     */
    public function defaultDocTemplate(): DocTemplate
    {
        return $this->docTemplates()->firstOrCreate(
            ['is_default' => true],
            [
                'name' => 'Standar '.$this->name,
                'doc_kinds' => config('spekta.doc_sets.3'),
                'language' => $this->locale ?? 'id',
                'tone' => 'formal',
                'config' => ['white_label' => true],
            ],
        );
    }

    public function subscription()
    {
        // ponytail: plain ordering, not latestOfMany() — that builds MAX(id) subqueries
        // and Postgres has no max(uuid). Safe because nothing eager-loads this relation.
        return $this->hasOne(Subscription::class)->orderByDesc('created_at')->orderByDesc('id');
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

    /**
     * BR-05/BR-02: guard bersama untuk endpoint yang memanggil LLM (analisa dampak,
     * cek kontradiksi, dst). Dulu diduplikasi di ImpactController::analyze/forChangeRequest —
     * disatukan di sini supaya titik guard baru
     * (mis. ProjectController::checkContradictions) tidak lupa menerapkannya.
     */
    public function assertAiAllowed(): void
    {
        // BR-05: mode read-only setelah grace period habis — panggilan LLM diblok.
        if ($this->subscription?->effectiveStatus() === 'readonly') {
            abort(403, 'Langganan berakhir — workspace read-only (BR-05).');
        }
        // BR-02: butuh kredit tersedia (analisa/cek TIDAK mengkonsumsi, hanya preview/gate).
        if ($this->creditBalance() < 1) {
            abort(402, 'Kredit blueprint habis. Upgrade paket atau top-up (BR-02).');
        }
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

    /**
     * BR-01: pemakaian cek kontradiksi bulan berjalan vs kuota paket (limit null = unlimited).
     * Panggilan LLM reasoning termahal — kuota terpisah dari chat, counter di cache (driver
     * database, persisten); kunci mengandung Y-m jadi reset otomatis tiap ganti bulan.
     */
    public function contradictionQuota(): array
    {
        $plan = $this->subscription?->plan ?? 'free';

        return [
            'used' => (int) Cache::get($this->contradictionUsageKey(), 0),
            'limit' => config("spekta.plans.{$plan}.contradiction_checks_per_month"),
        ];
    }

    public function recordContradictionCheck(): void
    {
        Cache::add($this->contradictionUsageKey(), 0, now()->addDays(45));
        Cache::increment($this->contradictionUsageKey());
    }

    private function contradictionUsageKey(): string
    {
        return 'contracheck-used:'.$this->id.':'.now()->format('Y-m');
    }
}
