<?php

namespace Tests\Feature;

use App\Jobs\RepairRunJob;
use App\Models\Project;
use App\Models\User;
use App\Services\SpecEngine;
use App\Services\SpecHealthValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpecEngineGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'g@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');

        $project = Project::firstOrFail();
        $project->inputs()->create(['kind' => 'idea', 'raw_text' => 'Aplikasi kasir QRIS dengan laporan penjualan.']);

        return $project;
    }

    private function anthropicConfig(): void
    {
        config([
            'spekta.llm.driver' => 'anthropic',
            'spekta.llm.anthropic_key' => 'sk-ant-test',
            'spekta.llm.base_url' => 'https://api.anthropic.com',
        ]);
    }

    private function anthropicResponse(string $text, string $stopReason = 'end_turn'): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 5, 'output_tokens' => 9],
        ];
    }

    public function test_json_invalid_direbut_sekali_lalu_sukses(): void
    {
        $this->anthropicConfig();
        $valid = json_encode(['roles' => [], 'features' => [['title' => 'F', 'quote' => '']], 'domain' => 'x', 'complexity' => 1, 'assumptions' => []]);
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropicResponse('bukan json'))
                ->push($this->anthropicResponse($valid)),
        ]);

        $out = app(SpecEngine::class)->understand($this->project());

        $this->assertSame('F', $out['features'][0]['title']);
        Http::assertSentCount(2);
    }

    public function test_json_invalid_dua_kali_throw(): void
    {
        $this->anthropicConfig();
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicResponse('tetap bukan json'))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JSON valid');
        app(SpecEngine::class)->understand($this->project());
    }

    public function test_truncation_max_tokens_throw(): void
    {
        $this->anthropicConfig();
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicResponse('{"terpotong', 'max_tokens'))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('terpotong');
        app(SpecEngine::class)->understand($this->project());
    }

    public function test_testing_bergantung_requirements_di_pipeline(): void
    {
        $this->assertContains('REQUIREMENTS', config('spekta.doc_pipeline.TESTING'));
    }

    private function putDoc(Project $project, string $key, string $content): void
    {
        $doc = $project->documents()->create(['doc_key' => $key, 'title' => "$key.md"]);
        $version = $doc->versions()->create(['version_no' => 1, 'content_md' => $content, 'source' => 'ai']);
        $doc->update(['current_version_id' => $version->id]);
    }

    public function test_validator_rule_baru(): void
    {
        $project = $this->project();
        $this->putDoc($project, 'PRD', "# PRD\n\n## FR-01 Kasir\n\n## Assumptions\n\n- a\n");
        $this->putDoc($project, 'REQUIREMENTS', "# REQ\n\n### FR-01: Kasir\n\nTanpa bullet AC di sini.\n");
        $this->putDoc($project, 'DATABASE', "# DB\n\ntabel tanpa diagram\n");
        $this->putDoc($project, 'WIREFRAMES', 'bukan { json');

        app(SpecHealthValidator::class)->run($project);

        $rules = $project->healthFindings()->pluck('rule_key');
        $this->assertContains('fr_ac_empty', $rules);
        $this->assertContains('db_has_erd', $rules);
        $this->assertContains('wireframes_json', $rules);
    }

    public function test_repair_run_hanya_sekali_dan_stub_tidak_bikin_versi(): void
    {
        config(['spekta.llm.driver' => 'stub']);
        $project = $this->project();
        $this->putDoc($project, 'PRD', "# PRD tanpa FR dan tanpa assumptions\n");
        $run = $project->generationRuns()->create(['trigger' => 'full', 'status' => 'done']);
        app(SpecHealthValidator::class)->run($project);
        $this->assertTrue($project->healthFindings()->where('severity', 'critical')->exists());

        (new RepairRunJob($run->id))->handle(app(SpecEngine::class));

        $run->refresh();
        $this->assertNotNull($run->repaired_at);
        // stub mengembalikan dokumen apa adanya → tidak ada versi baru
        $this->assertSame(1, $project->documents()->firstWhere('doc_key', 'PRD')->versions()->count());

        // pass kedua: langsung return, repaired_at tidak berubah
        $before = $run->repaired_at;
        (new RepairRunJob($run->id))->handle(app(SpecEngine::class));
        $this->assertEquals($before, $run->refresh()->repaired_at);
    }

    public function test_repair_menyimpan_versi_baru_saat_llm_mengubah_dokumen(): void
    {
        $this->anthropicConfig();
        $project = $this->project();
        $this->putDoc($project, 'PRD', "# PRD tanpa FR\n");
        $run = $project->generationRuns()->create(['trigger' => 'full', 'status' => 'done']);
        app(SpecHealthValidator::class)->run($project);

        Http::fake(['api.anthropic.com/*' => Http::response(
            $this->anthropicResponse("# PRD\n\n## FR-01 Kasir\n\n## Assumptions\n\n- a\n")
        )]);

        (new RepairRunJob($run->id))->handle(app(SpecEngine::class));

        $doc = $project->documents()->firstWhere('doc_key', 'PRD');
        $this->assertSame(2, $doc->versions()->count());
        $this->assertSame('ai-repair', $doc->currentVersion->generated_meta['generated_by'] ?? null);
        // FR-12 coverage: versi hasil RepairRunJob wajib tertag primaryLanguage() proyek, bukan
        // default kolom 'id' (lihat bugfix Project::primaryLanguage() — kolom projects.language mati).
        $this->assertSame($project->primaryLanguage(), $doc->currentVersion->language);
        // validator jalan ulang setelah repair — temuan prd_has_fr hilang
        $this->assertFalse($project->healthFindings()->where('rule_key', 'prd_has_fr')->exists());
    }
}
