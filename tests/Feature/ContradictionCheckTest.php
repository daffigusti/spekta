<?php

namespace Tests\Feature;

use App\Jobs\ContradictionCheckJob;
use App\Models\CreditLedger;
use App\Models\Project;
use App\Models\User;
use App\Services\SpecEngine;
use App\Services\SpecHealthValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContradictionCheckTest extends TestCase
{
    use RefreshDatabase;

    // Project::factory() belum ada (lihat ImpactAnalysisTest) — tiru pola register + POST /projects
    // supaya workspace & doc_template terpasang dengan benar.
    private function projectWithDocs(): Project
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $user = User::firstOrFail();
        $this->actingAs($user)->post('/projects');
        $project = Project::firstOrFail();

        foreach (['PRD' => 'ai', 'REQUIREMENTS' => 'user'] as $key => $source) {
            $doc = $project->documents()->create(['doc_key' => $key, 'title' => $key.'.md']);
            $v = $doc->versions()->create(['version_no' => 1, 'content_md' => "# $key\n## Isi", 'source' => $source]);
            $doc->update(['current_version_id' => $v->id]);
        }

        return $project;
    }

    public function test_job_replaces_contradiction_findings_and_recomputes_score(): void
    {
        $project = $this->projectWithDocs();
        $project->update(['health_score' => 100]);
        $project->healthFindings()->create([
            'rule_key' => 'contradiction', 'severity' => 'warning', 'message' => 'lama', 'location' => 'X',
        ]);

        (new ContradictionCheckJob($project->id))->handle(
            app(SpecEngine::class),
            app(SpecHealthValidator::class),
        );

        // stub → tidak ada kontradiksi; temuan lama terhapus, skor pulih
        $this->assertSame(0, $project->healthFindings()->where('rule_key', 'contradiction')->count());
        $this->assertSame(100, $project->fresh()->health_score);
    }

    public function test_validator_run_preserves_contradiction_findings(): void
    {
        $project = $this->projectWithDocs();
        $project->healthFindings()->create([
            'rule_key' => 'contradiction', 'severity' => 'warning', 'message' => 'tetap ada', 'location' => 'X',
        ]);

        app(SpecHealthValidator::class)->run($project);

        $this->assertSame(1, $project->healthFindings()->where('rule_key', 'contradiction')->count());
    }

    public function test_manual_endpoint_dispatches_job(): void
    {
        Queue::fake();
        $project = $this->projectWithDocs();
        $user = User::firstOrFail();

        $this->actingAs($user)->post(route('projects.health.contradictions', $project))->assertRedirect();

        Queue::assertPushed(ContradictionCheckJob::class);
    }

    public function test_manual_endpoint_blocked_when_credits_exhausted(): void
    {
        // BR-02/BR-05: cek kontradiksi dispatch job LLM (termahal) — wajib guard billing yang sama
        // dengan ImpactController::analyze/forChangeRequest.
        Queue::fake();
        $project = $this->projectWithDocs();
        $user = User::firstOrFail();
        $workspace = $project->workspace;

        CreditLedger::create([
            'workspace_id' => $workspace->id,
            'delta' => -$workspace->creditBalance(),
            'kind' => 'consume',
            'ref_type' => 'project',
            'ref_id' => $project->id,
            'idempotency_key' => 'test-zero-'.$project->id,
        ]);
        $this->assertSame(0.0, $workspace->fresh()->creditBalance());

        $this->actingAs($user)->post(route('projects.health.contradictions', $project))->assertStatus(402);

        Queue::assertNotPushed(ContradictionCheckJob::class);
    }

    public function test_manual_endpoint_blocked_when_readonly(): void
    {
        // BR-05: workspace read-only setelah grace period habis — cek kontradiksi (panggilan LLM) diblok.
        Queue::fake();
        $project = $this->projectWithDocs();
        $user = User::firstOrFail();
        $project->workspace->subscription->update([
            'plan' => 'starter',
            'period_end' => now()->subDays(10)->toDateString(),
        ]);

        $this->actingAs($user)->post(route('projects.health.contradictions', $project))->assertForbidden();

        Queue::assertNotPushed(ContradictionCheckJob::class);
    }
}
