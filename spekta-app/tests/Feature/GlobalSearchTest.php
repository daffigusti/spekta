<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pencarian global header (⌘K): proyek + dokumen dalam workspace aktif. */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_matching_projects_and_documents(): void
    {
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $user = User::firstOrFail();
        $this->actingAs($user)->post('/projects');
        $project = Project::firstOrFail();
        $project->update(['name' => 'Aplikasi Kasir']);
        $project->documents()->create(['doc_key' => 'PRD', 'title' => 'PRD.md']);

        // query terlalu pendek → kosong
        $this->getJson('/search?q=a')->assertOk()->assertExactJson(['projects' => [], 'documents' => []]);

        // cocok nama proyek — case-insensitive (Postgres LIKE case-sensitive, harus whereLike)
        $this->getJson('/search?q=KASIR')
            ->assertOk()
            ->assertJsonPath('projects.0.name', 'Aplikasi Kasir');

        // cocok doc_key dokumen
        $this->getJson('/search?q=PRD')
            ->assertOk()
            ->assertJsonPath('documents.0.doc_key', 'PRD');

        // tidak cocok apa pun
        $this->getJson('/search?q=zzzz')->assertOk()->assertExactJson(['projects' => [], 'documents' => []]);
    }

    public function test_search_is_scoped_to_current_workspace(): void
    {
        $this->post('/register', [
            'name' => 'A', 'company' => 'WS1', 'email' => 'a@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');
        Project::firstOrFail()->update(['name' => 'Rahasia WS1']);
        $this->post('/logout');

        $this->post('/register', [
            'name' => 'B', 'company' => 'WS2', 'email' => 'b@b.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::where('email', 'b@b.co')->firstOrFail())
            ->getJson('/search?q=Rahasia')
            ->assertOk()
            ->assertExactJson(['projects' => [], 'documents' => []]);
    }
}
