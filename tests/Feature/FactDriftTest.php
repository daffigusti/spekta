<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\SpecHealthValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-11(h): fact drift — angka di dokumen menyimpang dari fakta kanonik REQUIREMENTS
 * (generated_meta.fact_sheet). Deterministik tanpa LLM, jalan tiap validator run.
 */
class FactDriftTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_number_drift_against_canonical_fact(): void
    {
        $findings = SpecHealthValidator::factDriftFindings(
            ['Maksimal 3 cabang per akun (FR-01)'],
            ['ARCHITECTURE' => 'Sistem mendukung 5 cabang dengan sinkronisasi.'],
        );

        $this->assertCount(1, $findings);
        $this->assertSame('fact_drift', $findings[0]['rule_key']);
        $this->assertSame('ARCHITECTURE / cabang', $findings[0]['location']);
    }

    public function test_min_max_pair_not_false_positive_but_outside_set_flagged(): void
    {
        $facts = ['Minimal 2 cabang (FR-01)', 'Maksimal 5 cabang (FR-01)'];

        $this->assertSame([], SpecHealthValidator::factDriftFindings($facts, ['API' => 'Endpoint sinkronisasi untuk 5 cabang.']));
        $this->assertCount(1, SpecHealthValidator::factDriftFindings($facts, ['API' => 'Endpoint sinkronisasi untuk 7 cabang.']));
    }

    public function test_matching_number_and_unrelated_keyword_ignored(): void
    {
        $facts = ['Diskon maksimal 20% per transaksi (BR-03)'];

        // angka sama → aman; "20 persen" dianggap sama dengan "20%"; keyword lain tak dikenal → diabaikan
        $this->assertSame([], SpecHealthValidator::factDriftFindings($facts, [
            'BUSINESS_RULES' => "Diskon dibatasi 20% per transaksi.\nPengiriman butuh 14 hari kerja.",
        ]));
    }

    public function test_code_fence_fr_codes_and_requirements_skipped(): void
    {
        $facts = ['Maksimal 3 cabang per akun (FR-01)'];

        $this->assertSame([], SpecHealthValidator::factDriftFindings($facts, [
            'REQUIREMENTS' => 'Sumber kanon boleh sebut 9 cabang tanpa flag.',
            'ARCHITECTURE' => "Lihat FR-12 dan Fase 2.\n```\nreplicas: 5 cabang\n```\nTetap 3 cabang sesuai spec.",
        ]));
    }

    public function test_validator_run_persists_fact_drift_finding(): void
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');
        $project = Project::firstOrFail();

        $req = $project->documents()->create(['doc_key' => 'REQUIREMENTS', 'title' => 'REQUIREMENTS.md']);
        $v = $req->versions()->create([
            'version_no' => 1, 'content_md' => '# REQUIREMENTS', 'source' => 'ai',
            'generated_meta' => ['fact_sheet' => ['Maksimal 3 cabang per akun (FR-01)']],
        ]);
        $req->update(['current_version_id' => $v->id]);

        $arch = $project->documents()->create(['doc_key' => 'ARCHITECTURE', 'title' => 'ARCHITECTURE.md']);
        $av = $arch->versions()->create(['version_no' => 1, 'content_md' => 'Mendukung 5 cabang.', 'source' => 'ai']);
        $arch->update(['current_version_id' => $av->id]);

        app(SpecHealthValidator::class)->run($project);

        $this->assertSame(1, $project->healthFindings()->where('rule_key', 'fact_drift')->count());
    }
}
