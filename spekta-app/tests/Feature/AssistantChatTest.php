<?php

namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_replies_and_quota_enforced(): void
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $user = User::firstOrFail();
        $this->actingAs($user)->post('/projects');
        $project = Project::firstOrFail();

        // Chat tersimpan berpasangan user+assistant
        $this->post("/projects/{$project->id}/assistant", ['message' => 'Apa dampak menambah OVO?'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, AssistantMessage::count());
        $this->assertSame(1, AssistantMessage::where('role', 'assistant')->count());
        $this->assertStringContainsString('OVO', AssistantMessage::where('role', 'assistant')->first()->body);

        // Konteks layar wireframe terpilih ikut terkirim tanpa error validasi
        $this->post("/projects/{$project->id}/assistant", ['message' => 'Tambah field telepon', 'doc_key' => 'WIREFRAMES', 'screen' => 'Registrasi'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, AssistantMessage::where('role', 'assistant')->count());

        // BR-01: paket free = 10 chat/bln → habiskan sisa (2 sudah terpakai di atas) lalu tolak
        for ($i = 0; $i < 8; $i++) {
            AssistantMessage::create(['project_id' => $project->id, 'role' => 'user', 'body' => "q{$i}"]);
        }
        $this->post("/projects/{$project->id}/assistant", ['message' => 'kesebelas'])
            ->assertSessionHasErrors('assistant');
        $this->assertSame(10, AssistantMessage::where('role', 'user')->count()); // tetap 10, tidak nambah
    }

    public function test_scope_param_validated(): void
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');
        $project = Project::firstOrFail();

        // scope 'project' valid — konteks seluruh proyek
        $this->post("/projects/{$project->id}/assistant", ['message' => 'Risiko terbesar?', 'scope' => 'project'])
            ->assertSessionHasNoErrors();
        // scope tak dikenal ditolak validasi
        $this->post("/projects/{$project->id}/assistant", ['message' => 'x', 'scope' => 'galaxy'])
            ->assertSessionHasErrors('scope');
    }
}
