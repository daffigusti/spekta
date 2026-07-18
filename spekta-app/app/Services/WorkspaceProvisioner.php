<?php

namespace App\Services;

use App\Models\CreditLedger;
use App\Models\RateCard;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceProvisioner
{
    public function provision(User $owner, string $companyName): Workspace
    {
        // Atomic: gagal di tengah tidak boleh menyisakan workspace tanpa subscription/kredit/rate card
        return DB::transaction(function () use ($owner, $companyName) {
            $workspace = Workspace::create([
                'name' => $companyName,
                'slug' => Str::slug($companyName).'-'.Str::lower(Str::random(5)),
            ]);

            $workspace->members()->create(['user_id' => $owner->id, 'role' => 'owner']);

            Subscription::create([
                'workspace_id' => $workspace->id,
                'plan' => 'free',
                'seats' => 1,
                'period_start' => now()->startOfMonth(),
                'period_end' => now()->endOfMonth(),
                'status' => 'active',
            ]);

            // BR-01: Free = 2 blueprint/bulan; BR-03: kredit paket berlaku 1 bulan
            CreditLedger::create([
                'workspace_id' => $workspace->id,
                'delta' => config('spekta.plans.free.blueprints_per_month'),
                'kind' => 'plan_grant',
                'expires_at' => now()->endOfMonth(),
                'idempotency_key' => 'grant-'.$workspace->id.'-'.now()->format('Y-m'),
            ]);

            // Rate card default IDR (FEATURES.md 4: tanpa rate card estimator jalan MD-only,
            // tapi template default mempercepat onboarding — USER_FLOWS.md 7)
            RateCard::create([
                'workspace_id' => $workspace->id,
                'name' => 'Rate Card Default',
                'currency' => 'IDR',
                'is_default' => true,
                'margin_pct' => 30,
                'roles' => [
                    ['role' => 'FE', 'daily_rate' => 1_200_000],
                    ['role' => 'BE', 'daily_rate' => 1_300_000],
                    ['role' => 'QA', 'daily_rate' => 900_000],
                    ['role' => 'PM', 'daily_rate' => 1_100_000],
                    ['role' => 'DevOps', 'daily_rate' => 1_400_000],
                ],
            ]);

            return $workspace;
        });
    }
}
