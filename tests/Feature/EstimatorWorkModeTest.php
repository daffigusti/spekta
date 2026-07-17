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

    public function test_multiplier_efektif_hanya_kena_porsi_implementasi(): void
    {
        // FE 35% + BE 40% kena impl_multiplier; QA 15% + PM 10% tetap 1.0×
        $this->assertSame(1.0, Estimator::effectiveMultiplier('conservative'));
        $this->assertSame(0.7, Estimator::effectiveMultiplier('ai_assisted')); // 0.75×0.6 + 0.25
        $this->assertSame(0.55, Estimator::effectiveMultiplier('vibe'));       // 0.75×0.4 + 0.25
        $this->assertSame(1.0, Estimator::effectiveMultiplier('mode_tak_dikenal'));
    }

    public function test_vibe_mode_menurunkan_total_dan_menyimpan_baseline(): void
    {
        $est = app(Estimator::class)->compute($this->projectWithStructure('vibe'), 'mvp');

        // 10 MD × 1.1 overhead = 11 baseline; ×0.55 = 6.05; +15% buffer = 6.96
        $this->assertEqualsWithDelta(6.96, $est->total_md, 0.05);
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
}
