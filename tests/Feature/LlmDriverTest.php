<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmDriverTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $this->actingAs(User::firstOrFail())->post('/projects');

        return Project::firstOrFail();
    }

    public function test_openai_compatible_driver_used_for_understanding(): void
    {
        config([
            'spekta.llm.driver' => 'openai',
            'spekta.llm.openai_key' => 'sk-test',
            'spekta.llm.openai_base_url' => 'https://api.groq.com/openai/v1',
        ]);
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'roles' => [['name' => 'Admin', 'note' => '']],
                    'features' => [['title' => 'Kasir QRIS', 'quote' => '']],
                    'domain' => 'retail', 'complexity' => 2, 'assumptions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
            ]),
        ]);

        $project = $this->project();
        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'P', 'kind' => 'idea',
            'raw_text' => str_repeat('Aplikasi kasir dengan pembayaran QRIS dan laporan. ', 3),
        ])->assertSessionHasNoErrors();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.groq.com/openai/v1/chat/completions')
            && $req->header('Authorization')[0] === 'Bearer sk-test');
        $this->assertSame('Kasir QRIS', $project->refresh()->understanding->features[0]['title']);
    }

    public function test_anthropic_compatible_base_url_respected(): void
    {
        config([
            'spekta.llm.driver' => 'anthropic',
            'spekta.llm.anthropic_key' => 'sk-ant-test',
            'spekta.llm.base_url' => 'https://api.z.ai/api/anthropic',
        ]);
        Http::fake([
            'api.z.ai/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'roles' => [], 'features' => [['title' => 'Fitur A', 'quote' => '']],
                    'domain' => 'x', 'complexity' => 1, 'assumptions' => [],
                ])]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 9],
            ]),
        ]);

        $project = $this->project();
        $this->post("/projects/{$project->id}/wizard/input", [
            'name' => 'P', 'kind' => 'idea',
            'raw_text' => str_repeat('Aplikasi booking lapangan dengan pembayaran digital. ', 3),
        ])->assertSessionHasNoErrors();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.z.ai/api/anthropic/v1/messages')
            && $req->header('x-api-key')[0] === 'sk-ant-test');
    }
}
