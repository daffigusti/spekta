<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\SpecEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fact-sheet kanonik: fakta (angka/limit/aturan) diekstrak dari REQUIREMENTS versi current,
 * disimpan lazy di generated_meta versi ybs, dan disuntik ke konteks generate dokumen lain.
 * Kunci desain: cache per-VERSI — versi baru (edit manual/regen) tidak punya fact_sheet
 * sehingga otomatis diekstrak ulang; tidak ada jalur basi.
 */
class FactSheetTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');

        return Project::firstOrFail();
    }

    private function addRequirements(Project $project, ?array $meta = null): void
    {
        $doc = $project->documents()->create(['doc_key' => 'REQUIREMENTS', 'title' => 'REQUIREMENTS.md']);
        $v = $doc->versions()->create([
            'version_no' => 1, 'content_md' => "# REQUIREMENTS\nFR-01: maks 3 cabang.", 'source' => 'ai',
            'generated_meta' => $meta,
        ]);
        $doc->update(['current_version_id' => $v->id]);
    }

    public function test_empty_when_no_requirements_doc(): void
    {
        $this->assertSame([], app(SpecEngine::class)->factSheet($this->project()));
    }

    public function test_extraction_persisted_lazily_on_current_version(): void
    {
        $project = $this->project();
        $this->addRequirements($project);

        $facts = app(SpecEngine::class)->factSheet($project);

        // stub → fakta kosong, tapi kuncinya WAJIB tersimpan supaya tidak ekstrak ulang tiap panggilan
        $this->assertSame([], $facts);
        $meta = $project->documents()->firstOrFail()->currentVersion->fresh()->generated_meta;
        $this->assertArrayHasKey('fact_sheet', $meta);
    }

    public function test_stored_facts_returned_without_reextraction(): void
    {
        $project = $this->project();
        $this->addRequirements($project, ['fact_sheet' => ['Maks 3 cabang per akun (FR-01)']]);

        $this->assertSame(['Maks 3 cabang per akun (FR-01)'], app(SpecEngine::class)->factSheet($project));
    }

    public function test_new_version_reextracts_old_version_untouched(): void
    {
        $project = $this->project();
        $this->addRequirements($project, ['fact_sheet' => ['Maks 3 cabang per akun (FR-01)']]);
        $doc = $project->documents()->firstOrFail();
        $v1 = $doc->currentVersion;

        // edit manual → versi baru tanpa fact_sheet jadi current
        $v2 = $doc->versions()->create([
            'version_no' => 2, 'content_md' => "# REQUIREMENTS\nFR-01: maks 5 cabang.", 'source' => 'user',
        ]);
        $doc->update(['current_version_id' => $v2->id]);

        // fakta lama TIDAK boleh terbawa — diekstrak ulang dari versi baru (stub → kosong)
        $this->assertSame([], app(SpecEngine::class)->factSheet($project->fresh()));
        $this->assertArrayHasKey('fact_sheet', $v2->fresh()->generated_meta);
        $this->assertSame(['Maks 3 cabang per akun (FR-01)'], $v1->fresh()->generated_meta['fact_sheet']);
    }
}
