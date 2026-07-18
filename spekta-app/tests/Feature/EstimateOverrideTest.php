<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateOverrideTest extends TestCase
{
    use RefreshDatabase;

    /** Proyek dengan 1 fase + 2 fitur (10 & 20 MD, tanpa sub-fitur). */
    private function projectWithStructure(): Project
    {
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');

        $project = Project::firstOrFail();
        $phase = $project->structureNodes()->create(['kind' => 'phase', 'title' => 'Fase 1', 'sort' => 1]);
        $project->structureNodes()->create([
            'kind' => 'feature', 'parent_id' => $phase->id, 'title' => 'Fitur A', 'scope' => 'mvp', 'est_md' => 10, 'sort' => 1,
        ]);
        $project->structureNodes()->create([
            'kind' => 'feature', 'parent_id' => $phase->id, 'title' => 'Fitur B', 'scope' => 'mvp', 'est_md' => 20, 'sort' => 2,
        ]);

        return $project;
    }

    /** FR-15: override harus memperbarui total, timeline, dan komposisi tim — bukan hanya total_md/cost. */
    public function test_override_memperbarui_line_total_timeline_dan_komposisi(): void
    {
        $project = $this->projectWithStructure();
        $this->get("/projects/{$project->id}/estimate")->assertOk();

        $est = $project->estimates()->where('scope', 'full')->firstOrFail();
        $line = $est->lines()->whereHas('structureNode', fn ($q) => $q->where('title', 'Fitur A'))->firstOrFail();
        $aiMd = $line->ai_md; // 10 × 1.1 = 11

        $this->patch("/projects/{$project->id}/estimates/{$est->id}/lines/{$line->id}", [
            'md' => 5, 'reason' => 'modul serupa sudah pernah dibangun',
        ])->assertRedirect();

        $line->refresh();
        $est->refresh();

        $this->assertSame(5.0, $line->md);
        $this->assertTrue($line->overridden);
        $this->assertSame($aiMd, $line->ai_md); // estimasi AI asli tidak berubah

        // Total: (5 + 22) × 1.15 buffer = 31.05
        $this->assertEqualsWithDelta(31.05, $est->total_md, 0.1);
        // Timeline rekonsiliasi dengan total (bug lama: timeline basi setelah override)
        $this->assertEqualsWithDelta($est->total_md, array_sum(array_column($est->timeline, 'md')), 0.2);
        // Komposisi tim ikut total baru
        $this->assertEqualsWithDelta($est->total_md, array_sum(array_column($est->team_composition, 'md')), 0.3);
    }

    public function test_override_validasi_md_dan_alasan(): void
    {
        $project = $this->projectWithStructure();
        $this->get("/projects/{$project->id}/estimate");
        $est = $project->estimates()->where('scope', 'full')->firstOrFail();
        $line = $est->lines()->firstOrFail();

        $this->from("/projects/{$project->id}/estimate")
            ->patch("/projects/{$project->id}/estimates/{$est->id}/lines/{$line->id}", ['md' => 5])
            ->assertSessionHasErrors('reason');

        $this->from("/projects/{$project->id}/estimate")
            ->patch("/projects/{$project->id}/estimates/{$est->id}/lines/{$line->id}", ['md' => -1, 'reason' => 'x'])
            ->assertSessionHasErrors('md');
    }

    /** Warning <50% dari estimasi AI hanya di UI — server tetap menerima (non-blocking). */
    public function test_override_di_bawah_50_persen_tetap_diterima(): void
    {
        $project = $this->projectWithStructure();
        $this->get("/projects/{$project->id}/estimate");
        $est = $project->estimates()->where('scope', 'full')->firstOrFail();
        $line = $est->lines()->firstOrFail();

        $this->patch("/projects/{$project->id}/estimates/{$est->id}/lines/{$line->id}", [
            'md' => round($line->ai_md * 0.2, 1), 'reason' => 'sengaja underestimate',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    /** FR-14: switcher mode — recompute dengan work_mode persist ke blueprint & reset override. */
    public function test_recompute_dengan_work_mode_memperbarui_blueprint_dan_reset_override(): void
    {
        $project = $this->projectWithStructure();
        $this->get("/projects/{$project->id}/estimate");
        $est = $project->estimates()->where('scope', 'full')->firstOrFail();
        $line = $est->lines()->firstOrFail();
        $this->patch("/projects/{$project->id}/estimates/{$est->id}/lines/{$line->id}", ['md' => 3, 'reason' => 'x']);

        $this->post("/projects/{$project->id}/estimate/recompute", ['work_mode' => 'vibe'])->assertRedirect();

        $project->refresh();
        $this->assertSame('vibe', $project->blueprint['work_mode']);
        foreach ($project->estimates as $e) {
            $this->assertSame('vibe', $e->work_mode);
            $this->assertFalse($e->lines()->where('overridden', true)->exists()); // recompute reset override
        }

        $this->from("/projects/{$project->id}/estimate")
            ->post("/projects/{$project->id}/estimate/recompute", ['work_mode' => 'ngawur'])
            ->assertSessionHasErrors('work_mode');
    }

    /** Rate card kosong → md_only; props work_mode/baseline_md/ai_md wajib terkirim (regresi badge baseline). */
    public function test_md_only_dan_props_estimasi_terkirim(): void
    {
        $project = $this->projectWithStructure();
        $card = $project->workspace->rateCards()->where('is_default', true)->firstOrFail();
        $card->update(['roles' => collect($card->roles)->map(fn ($r) => [...$r, 'daily_rate' => 0])->all()]);

        $this->get("/projects/{$project->id}/estimate")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('estimate')
                ->where('estimates.0.md_only', true)
                ->has('estimates.0.work_mode')
                ->has('estimates.0.baseline_md')
                ->has('estimates.0.lines.0.ai_md')
                ->has('workModes.conservative'));
    }
}
