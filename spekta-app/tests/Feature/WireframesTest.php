<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WireframesTest extends TestCase
{
    use RefreshDatabase;

    public function test_wireframes_generated_as_valid_json_and_rendered_on_canvas_page(): void
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'w@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $user = User::firstOrFail();
        $this->actingAs($user)->post('/projects');
        $project = Project::firstOrFail();

        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'Kasir', 'kind' => 'idea',
            'raw_text' => str_repeat('Aplikasi kasir toko retail dengan QRIS dan laporan penjualan. ', 3),
        ]);
        $this->post("/projects/{$project->id}/wizard/understanding", ['confirmed' => true]);
        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'full']);
        $this->post("/projects/{$project->id}/wizard/stack/confirm");
        $this->post("/projects/{$project->id}/generate")->assertSessionHasNoErrors();

        // WIREFRAMES ikut pipeline & content-nya JSON valid berisi screens per flow
        $doc = $project->documents()->where('doc_key', 'WIREFRAMES')->first();
        $this->assertNotNull($doc);
        $data = json_decode($doc->currentVersion->content_md, true);
        $this->assertIsArray($data['screens'] ?? null);
        $this->assertNotEmpty($data['screens']);
        $this->assertArrayHasKey('flow', $data['screens'][0]);
        $this->assertArrayHasKey('sections', $data['screens'][0]);

        // Halaman canvas tampil dengan dokumen wireframe
        $this->get("/projects/{$project->id}/wireframes")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('wireframes')
                ->where('document.doc_key', 'WIREFRAMES')
                ->has('document.content_md'));

        // WIREFRAMES tidak muncul di daftar dokumen markdown halaman project
        $this->get("/projects/{$project->id}")
            ->assertInertia(fn ($page) => $page
                ->component('project')
                ->where('documents', fn ($docs) => collect($docs)->pluck('doc_key')->doesntContain('WIREFRAMES')));
    }
}
