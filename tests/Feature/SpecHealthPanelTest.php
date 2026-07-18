<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\Exporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel Spec Health: dimensi per rule_key (config health_dimensions), badge STALE
 * (upstream punya versi lebih baru), dan info dependency upstream/downstream dari doc_pipeline.
 */
class SpecHealthPanelTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(): Project
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');

        return Project::firstOrFail();
    }

    private function makeDoc(Project $project, string $key, string $md, string $createdAt): void
    {
        $doc = $project->documents()->create(['doc_key' => $key, 'title' => "$key.md"]);
        $v = $doc->versions()->create(['version_no' => 1, 'content_md' => $md, 'source' => 'ai']);
        $v->timestamps = false;
        $v->update(['created_at' => $createdAt]);
        $doc->update(['current_version_id' => $v->id]);
    }

    public function test_findings_carry_dimension_from_config_mapping(): void
    {
        $project = $this->makeProject();
        $project->healthFindings()->create([
            'rule_key' => 'fr_in_roadmap', 'severity' => 'warning',
            'location' => 'ROADMAP / FR-01', 'message' => 'FR-01 tidak muncul di ROADMAP.md',
        ]);
        $project->healthFindings()->create([
            'rule_key' => 'rule_tanpa_mapping', 'severity' => 'info',
            'location' => 'PRD', 'message' => 'temuan lain',
        ]);

        $this->get(route('projects.show', $project))
            ->assertInertia(fn ($page) => $page
                ->component('project')
                ->where('findings.0.dimension', 'Keterlacakan')
                ->where('findings.1.dimension', 'Lainnya')
                ->where('health_dimensions', array_keys(config('spekta.health_dimensions'))));
    }

    public function test_document_stale_when_upstream_has_newer_version(): void
    {
        $project = $this->makeProject();
        // API bergantung ke REQUIREMENTS (doc_pipeline); REQUIREMENTS lebih baru → API stale
        $this->makeDoc($project, 'REQUIREMENTS', '# REQ', '2026-07-18 10:00:00');
        $this->makeDoc($project, 'API', '# API', '2026-07-18 09:00:00');

        $this->get(route('projects.show', $project))
            ->assertInertia(fn ($page) => $page
                ->where('documents.0.doc_key', 'REQUIREMENTS')
                ->where('documents.0.stale', false)
                ->where('documents.1.doc_key', 'API')
                ->where('documents.1.stale', true));
    }

    public function test_rtm_rows_per_fr_with_null_for_missing_docs(): void
    {
        $project = $this->makeProject();
        $this->makeDoc($project, 'PRD', "# PRD\nFR-01 login\nFR-02 laporan", '2026-07-18 09:00:00');
        $this->makeDoc($project, 'REQUIREMENTS', "## FR-01\n- AC login", '2026-07-18 09:00:00');

        $this->get(route('projects.show', $project))
            ->assertInertia(fn ($page) => $page
                ->where('rtm.0.fr', 'FR-01')
                ->where('rtm.0.cells.REQUIREMENTS', true)
                ->where('rtm.0.cells.TESTING', null) // dokumen belum ada
                ->where('rtm.1.fr', 'FR-02')
                ->where('rtm.1.cells.REQUIREMENTS', false));
    }

    public function test_open_questions_aggregate_interview_understanding(): void
    {
        $project = $this->makeProject();
        $project->interviewItems()->create(['seq' => 1, 'question' => 'Perlu multi-bahasa?', 'skipped' => true]);
        $project->interviewItems()->create(['seq' => 2, 'question' => 'Sudah dijawab', 'skipped' => false, 'answer_text' => 'ya']);
        $project->understanding()->create([
            'assumptions' => ['Integrasi pembayaran tidak disebut'],
            'contradictions' => ['Budget kecil tapi scope besar'],
        ]);

        $this->get(route('projects.show', $project))
            ->assertInertia(fn ($page) => $page
                ->where('open_questions.skipped_questions', ['Perlu multi-bahasa?'])
                ->where('open_questions.assumptions', ['Integrasi pembayaran tidak disebut'])
                ->where('open_questions.contradictions', ['Budget kecil tapi scope besar']));
    }

    public function test_documents_carry_group_and_zip_export_contains_branded_readme(): void
    {
        $project = $this->makeProject();
        $this->makeDoc($project, 'REQUIREMENTS', '# REQ', '2026-07-18 09:00:00');
        $this->makeDoc($project, 'USER_FLOWS', '# UF', '2026-07-18 09:00:00');

        $this->get(route('projects.show', $project))
            ->assertInertia(fn ($page) => $page
                ->where('documents.0.group', 'Planning')
                ->where('documents.1.group', 'Design'));

        $path = app(Exporter::class)->zip($project, 'zip');
        $zip = new \ZipArchive;
        $zip->open($path);
        $readme = $zip->getFromName('README.md');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('Spekta Blueprint', $readme);
        $this->assertStringContainsString('| 01 | `REQUIREMENTS.md` | Planning |', $readme);
        $this->assertStringContainsString('| 02 | `USER_FLOWS.md` | Design |', $readme);
    }

    public function test_document_carries_upstream_and_downstream_limited_to_existing_docs(): void
    {
        $project = $this->makeProject();
        $this->makeDoc($project, 'REQUIREMENTS', '# REQ', '2026-07-18 09:00:00');
        $this->makeDoc($project, 'API', '# API', '2026-07-18 10:00:00');

        $this->get(route('projects.show', $project))
            ->assertInertia(fn ($page) => $page
                // PRD tidak ada di proyek → upstream REQUIREMENTS kosong; downstream hanya API (TESTING dkk tak ada)
                ->where('documents.0.upstream', [])
                ->where('documents.0.downstream', ['API'])
                ->where('documents.1.upstream.0.doc_key', 'REQUIREMENTS')
                ->where('documents.1.upstream.0.version_no', 1)
                ->where('documents.1.downstream', []));
    }
}
