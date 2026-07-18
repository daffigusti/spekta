<?php

namespace Tests\Feature;

use App\Jobs\WizardStepJob;
use App\Models\Project;
use App\Models\User;
use App\Services\SpecEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WizardStepJobTest extends TestCase
{
    use RefreshDatabase;

    private function projectSiapStructureConfirm(): Project
    {
        config(['spekta.llm.driver' => 'stub']);

        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'r@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');
        $project = Project::firstOrFail();

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'Toko Online', 'kind' => 'idea',
            'raw_text' => 'Aplikasi toko online dengan katalog produk. Keranjang belanja. Pembayaran transfer.',
        ]);
        $project->refresh();
        $this->post("/projects/{$project->id}/wizard/understanding", [
            'roles' => [], 'features' => $project->understanding->features, 'complexity' => 2, 'assumptions' => [],
        ]);
        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);

        return $project->refresh();
    }

    public function test_confirm_structure_dispatch_job_stack_sekali_meski_dobel_submit(): void
    {
        $project = $this->projectSiapStructureConfirm();
        $this->assertSame('structure', $project->wizard_step);

        Queue::fake();
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'full']);
        // Klik ganda / tab kedua: guard cache status queued harus menolak dispatch kedua
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'full']);

        Queue::assertPushed(WizardStepJob::class, 1);
        $this->assertSame('queued', Cache::get(WizardStepJob::statusKey($project->id))['status']);
    }

    public function test_job_gagal_menulis_status_error_dan_boleh_retry(): void
    {
        $project = $this->projectSiapStructureConfirm();

        // Driver invalid → panggilan LLM di job melempar exception
        config(['spekta.llm.driver' => 'openai', 'spekta.llm.openai_key' => null, 'spekta.llm.base_url' => 'http://127.0.0.1:1']);
        try {
            (new WizardStepJob($project->id, 'stack'))->handle(app(SpecEngine::class));
            $this->fail('Job seharusnya melempar exception');
        } catch (\Throwable) {
        }

        $status = Cache::get(WizardStepJob::statusKey($project->id));
        $this->assertSame('error', $status['status']);
        $this->assertNotEmpty($status['error']);

        // Status error tidak memblokir dispatch ulang (retry manual dari UI)
        config(['spekta.llm.driver' => 'stub']);
        Queue::fake();
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'full']);
        Queue::assertPushed(WizardStepJob::class, 1);
    }
}
