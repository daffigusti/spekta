<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\Estimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimatorWorkModeTest extends TestCase
{
    use RefreshDatabase;

    /** Proyek dengan 1 fase + 1 fitur 10 MD (tanpa sub-fitur). */
    private function projectWithStructure(?string $workMode): Project
    {
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'w@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');

        $project = Project::firstOrFail();
        if ($workMode) {
            $project->update(['blueprint' => ['work_mode' => $workMode]]);
        }
        $phase = $project->structureNodes()->create(['kind' => 'phase', 'title' => 'Fase 1', 'sort' => 1]);
        $project->structureNodes()->create([
            'kind' => 'feature', 'parent_id' => $phase->id, 'title' => 'Fitur A',
            'scope' => 'mvp', 'est_md' => 10, 'sort' => 1,
        ]);

        return $project;
    }

    public function test_role_split_konsisten_dan_berjumlah_satu(): void
    {
        $split = Estimator::roleSplit();
        $this->assertEqualsWithDelta(1.0, array_sum($split), 0.001);
        $this->assertArrayHasKey('DevOps', $split); // tarif DevOps ikut dihitung
    }

    public function test_multiplier_efektif_hanya_kena_porsi_implementasi(): void
    {
        // FE 33% + BE 38% kena impl_multiplier; QA/PM/DevOps 29% tetap 1.0×
        $this->assertSame(1.0, Estimator::effectiveMultiplier('conservative'));
        $this->assertSame(0.716, Estimator::effectiveMultiplier('ai_assisted')); // 0.71×0.6 + 0.29
        $this->assertSame(0.574, Estimator::effectiveMultiplier('vibe'));        // 0.71×0.4 + 0.29
        $this->assertSame(1.0, Estimator::effectiveMultiplier('mode_tak_dikenal'));
    }

    public function test_tarif_devops_memengaruhi_total_cost(): void
    {
        $project = $this->projectWithStructure(null);
        $card = $project->workspace->rateCards()->where('is_default', true)->firstOrFail();

        $costBefore = app(Estimator::class)->compute($project, 'mvp')->total_cost;

        $card->update(['roles' => collect($card->roles)->map(
            fn ($r) => $r['role'] === 'DevOps' ? [...$r, 'daily_rate' => 0] : $r
        )->all()]);
        $costAfter = app(Estimator::class)->compute($project->fresh(), 'mvp')->total_cost;

        $this->assertLessThan($costBefore, $costAfter);
    }

    public function test_vibe_mode_menurunkan_total_dan_menyimpan_baseline(): void
    {
        $est = app(Estimator::class)->compute($this->projectWithStructure('vibe'), 'mvp');

        // 10 MD × 1.1 overhead = 11 baseline; ×0.574 = 6.31; +15% buffer = 7.26
        $this->assertEqualsWithDelta(7.26, $est->total_md, 0.05);
        $this->assertEqualsWithDelta(12.65, $est->baseline_md, 0.06); // 11 × 1.15
        $this->assertSame('vibe', $est->work_mode);
        $this->assertSame(25, (int) $est->range_pct);
    }

    public function test_tanpa_work_mode_fallback_conservative(): void
    {
        $est = app(Estimator::class)->compute($this->projectWithStructure(null), 'mvp');

        $this->assertSame('conservative', $est->work_mode);
        $this->assertEqualsWithDelta(12.65, $est->total_md, 0.06); // angka lama tidak berubah
        $this->assertSame(15, (int) $est->range_pct);
    }

    /** Bug lama: fitur parked ikut masuk estimate full tapi tidak masuk timeline/proposal. */
    public function test_parked_tidak_masuk_full_scope_dan_timeline_rekonsiliasi(): void
    {
        $project = $this->projectWithStructure(null);
        $phase = $project->structureNodes()->where('kind', 'phase')->firstOrFail();
        $project->structureNodes()->create([
            'kind' => 'feature', 'parent_id' => $phase->id, 'title' => 'Fitur Parkir',
            'scope' => 'parked', 'est_md' => 100, 'sort' => 2,
        ]);

        $est = app(Estimator::class)->compute($project->fresh(), 'full');

        // 100 MD parked tidak boleh terhitung — total tetap ≈ 12.65 (10 × 1.1 × 1.15)
        $this->assertEqualsWithDelta(12.65, $est->total_md, 0.06);
        // Σ timeline md == total_md (rekonsiliasi RAB vs Gantt)
        $this->assertEqualsWithDelta($est->total_md, array_sum(array_column($est->timeline, 'md')), 0.2);
    }
}
