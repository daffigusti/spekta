<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Open questions dijawab klien via portal — jawaban tersimpan (answered_by, answered_at),
 * hanya kontak terverifikasi OTP, dan pertanyaan answered tidak bisa ditimpa.
 */
class OpenQuestionPortalTest extends TestCase
{
    use RefreshDatabase;

    private function makeShared(): array
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $user = User::firstOrFail();
        $this->actingAs($user)->post('/projects');
        $project = Project::firstOrFail();

        $doc = $project->documents()->create(['doc_key' => 'PRD', 'title' => 'PRD.md']);
        $v = $doc->versions()->create(['version_no' => 1, 'content_md' => '# PRD', 'source' => 'ai']);
        $doc->update(['current_version_id' => $v->id]);

        $oq = $project->openQuestions()->create([
            'source' => 'assumption', 'question' => 'Apakah perlu integrasi WhatsApp?',
            'question_hash' => sha1('assumption|Apakah perlu integrasi WhatsApp?'),
        ]);

        $link = $project->shareLinks()->create([
            'token' => str_repeat('t', 40), 'approver_email' => 'budi@majujaya.co.id',
            'contact_emails' => [], 'doc_keys' => ['PRD'],
            'expires_at' => now()->addDays(7), 'created_by' => $user->id,
        ]);

        return [$project, $link, $oq];
    }

    private function verify($link, string $email = 'budi@majujaya.co.id'): void
    {
        $this->post("/portal/{$link->token}/request-otp", ['email' => $email])->assertSessionHasNoErrors();
        $code = Cache::get("portal-otp:{$link->id}:".strtolower($email));
        $this->post("/portal/{$link->token}/verify", ['code' => $code])->assertSessionHasNoErrors();
    }

    public function test_client_answers_open_question_via_portal(): void
    {
        [$project, $link, $oq] = $this->makeShared();

        // belum verifikasi OTP → ditolak
        $this->post("/portal/{$link->token}/open-questions/{$oq->id}/answer", ['answer' => 'Ya'])->assertForbidden();

        $this->verify($link);
        $this->get("/portal/{$link->token}")->assertInertia(fn ($page) => $page
            ->where('open_questions.0.question', 'Apakah perlu integrasi WhatsApp?')
            ->where('open_questions.0.status', 'open'));

        $this->post("/portal/{$link->token}/open-questions/{$oq->id}/answer", ['answer' => 'Ya, WhatsApp Business API'])
            ->assertSessionHasNoErrors();

        $oq->refresh();
        $this->assertSame('answered', $oq->status);
        $this->assertSame('Ya, WhatsApp Business API', $oq->answer_text);
        $this->assertSame('budi@majujaya.co.id', $oq->answered_by);
        $this->assertNotNull($oq->answered_at);

        // sudah answered → tidak bisa ditimpa
        $this->post("/portal/{$link->token}/open-questions/{$oq->id}/answer", ['answer' => 'Ganti jawaban'])
            ->assertNotFound();

        // panel internal ikut menampilkan jawaban
        $this->actingAs(User::firstOrFail())->get(route('projects.show', $project))
            ->assertInertia(fn ($page) => $page
                ->where('open_questions.0.status', 'answered')
                ->where('open_questions.0.answer_text', 'Ya, WhatsApp Business API'));
    }
}
