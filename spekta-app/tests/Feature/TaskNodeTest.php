<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\Estimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Level task (kind='task') di bawah sub-fitur: generate dari stub,
 * CRUD, roll-up estimasi rekursif, dan halaman tasks.
 */
class TaskNodeTest extends TestCase
{
    use RefreshDatabase;

    private function projectDenganStruktur(): Project
    {
        config(['spekta.llm.driver' => 'stub']);

        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 't@a.co',
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
        // queue sync di test — WizardStepJob structure langsung jalan
        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);

        return $project->refresh();
    }

    public function test_struktur_stub_menghasilkan_task_di_bawah_subfitur(): void
    {
        $project = $this->projectDenganStruktur();

        $sub = $project->structureNodes()->where('kind', 'subfeature')->firstOrFail();
        $tasks = $project->structureNodes()->where('kind', 'task')->where('parent_id', $sub->id)->get();

        $this->assertNotEmpty($tasks);
        $this->assertNotNull($tasks->first()->description);
        // Invarian stub: jumlah est task = est parent sub-fiturnya
        $this->assertEqualsWithDelta((float) $sub->est_md, $tasks->sum('est_md'), 0.001);
    }

    public function test_crud_task_store_update_park(): void
    {
        $project = $this->projectDenganStruktur();
        $sub = $project->structureNodes()->where('kind', 'subfeature')->firstOrFail();

        $this->post("/projects/{$project->id}/wizard/nodes", [
            'parent_id' => $sub->id, 'kind' => 'task', 'title' => 'Task manual', 'est_md' => 1.5,
        ])->assertSessionHasNoErrors();
        $task = $project->structureNodes()->where('kind', 'task')->where('title', 'Task manual')->firstOrFail();

        $this->patch("/projects/{$project->id}/wizard/nodes/{$task->id}", [
            'description' => 'Deskripsi baru', 'status' => 'doing',
        ])->assertSessionHasNoErrors();
        $task->refresh();
        $this->assertSame('Deskripsi baru', $task->description);
        $this->assertSame('doing', $task->status);

        $this->delete("/projects/{$project->id}/wizard/nodes/{$task->id}");
        $this->assertSame('parked', $task->refresh()->scope);
    }

    public function test_task_tidak_boleh_di_bawah_phase(): void
    {
        $project = $this->projectDenganStruktur();
        $phase = $project->structureNodes()->where('kind', 'phase')->firstOrFail();

        $this->post("/projects/{$project->id}/wizard/nodes", [
            'parent_id' => $phase->id, 'kind' => 'task', 'title' => 'Nyasar',
        ])->assertStatus(422);
    }

    public function test_estimator_rollup_rekursif_dan_exclude_parked(): void
    {
        $project = $this->projectDenganStruktur();

        $feature = $project->structureNodes()->where('kind', 'feature')->firstOrFail();
        $sub = $project->structureNodes()->where('kind', 'subfeature')->where('parent_id', $feature->id)->orderBy('sort')->firstOrFail();
        $tasks = $project->structureNodes()->where('kind', 'task')->where('parent_id', $sub->id)->orderBy('sort')->get();
        $this->assertNotEmpty($tasks);

        $mdSebelum = app(Estimator::class)->compute($project->refresh(), 'full')
            ->lines->firstWhere('structure_node_id', $feature->id)->md;

        // Parkir satu task → leaf-sum fitur turun sebesar est task itu (× overhead integrasi)
        $parked = $tasks->first();
        $parked->update(['scope' => 'parked']);
        $project->unsetRelation('structureNodes');

        $estimate = app(Estimator::class)->compute($project->refresh(), 'full');
        $mdSesudah = $estimate->lines->firstWhere('structure_node_id', $feature->id)->md;

        $delta = (float) $parked->est_md
            * (1 + config('spekta.estimate.integration_overhead_pct') / 100)
            * Estimator::effectiveMultiplier($estimate->work_mode);
        $this->assertEqualsWithDelta($mdSebelum - $delta, $mdSesudah, 0.05);
    }

    public function test_generate_task_ai_untuk_struktur_existing(): void
    {
        $project = $this->projectDenganStruktur();
        $project->structureNodes()->where('kind', 'task')->delete();
        $sub = $project->structureNodes()->where('kind', 'subfeature')->firstOrFail();

        // queue sync di test — job generate langsung jalan
        $this->post("/projects/{$project->id}/tasks/generate")->assertSessionHasNoErrors();

        $tasks = $project->structureNodes()->where('kind', 'task')->where('parent_id', $sub->id)->get();
        $this->assertNotEmpty($tasks);
        $this->assertEqualsWithDelta((float) $sub->est_md, $tasks->sum('est_md'), 0.001);
    }

    public function test_halaman_tasks_terrender_dengan_nodes(): void
    {
        $project = $this->projectDenganStruktur();

        $this->get("/projects/{$project->id}/tasks")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('tasks')->has('nodes'));
    }
}
