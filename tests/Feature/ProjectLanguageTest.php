<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-12 bugfix: Project::primaryLanguage() dulu baca kolom projects.language yang TIDAK PERNAH
 * ditulis aplikasi (selalu default 'id'). Bahasa proyek nyata ditulis WizardController::saveInput()
 * ke blueprint['language'] — test ini menempuh jalur writer ASLI (POST wizard input), bukan set
 * kolom manual, supaya regresi kolom mati kembali tertangkap kalau primaryLanguage() dikembalikan
 * baca kolom itu lagi.
 */
class ProjectLanguageTest extends TestCase
{
    use RefreshDatabase;

    private function projectEnViaWizard(): Project
    {
        config(['spekta.llm.driver' => 'stub']);
        $this->post('/register', [
            'name' => 'M', 'company' => 'AC', 'email' => 'o@a.co',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $user = User::firstOrFail();
        $this->actingAs($user)->post('/projects');
        $project = Project::firstOrFail();

        $this->post("/projects/{$project->id}/wizard/input", [
            'kind' => 'idea',
            'language' => 'en',
            'raw_text' => 'A multi-branch cashier app with QRIS payment, daily sales reports, and inventory management with low-stock notifications.',
        ]);

        return $project->refresh();
    }

    public function test_primary_and_variant_language_follow_blueprint_not_dead_column(): void
    {
        $project = $this->projectEnViaWizard();

        $this->assertSame('en', $project->blueprint['language']);
        // Kolom projects.language TIDAK PERNAH ditulis aplikasi — tetap default 'id' meski
        // proyek ini berbahasa EN. primaryLanguage() TIDAK BOLEH bergantung padanya.
        $this->assertSame('id', $project->getAttribute('language'));

        $this->assertSame('en', $project->primaryLanguage());
        $this->assertSame('id', $project->variantLanguage());
    }

    public function test_generate_job_tags_version_language_from_blueprint(): void
    {
        $project = $this->projectEnViaWizard();

        // Alur wizard penuh (pola ChangeRequestTest::approvedProject()) sampai generate.
        $this->post("/projects/{$project->id}/wizard/understanding", [
            'roles' => [], 'features' => $project->understanding->features, 'complexity' => 1, 'assumptions' => [],
        ]);
        $this->post("/projects/{$project->id}/wizard/interview/finish", ['skip_all' => true]);
        $this->post("/projects/{$project->id}/wizard/structure/confirm", ['scope_mode' => 'mvp']);
        $this->post("/projects/{$project->id}/wizard/stack/confirm");
        $this->post("/projects/{$project->id}/generate");

        $project->refresh();
        $doc = $project->documents()->whereNotNull('current_version_id')->first();
        $this->assertNotNull($doc, 'generate pipeline seharusnya menghasilkan minimal satu dokumen');
        $this->assertSame('en', $doc->currentVersion->language);
        $this->assertSame($project->primaryLanguage(), $doc->currentVersion->language);
    }
}
