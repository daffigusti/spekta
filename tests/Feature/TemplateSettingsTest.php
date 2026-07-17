<?php

namespace Tests\Feature;

use App\Models\DocTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** Registrasi memprovision workspace + owner member (mirror ChangeRequestTest). */
    private function owner(): User
    {
        $this->post('/register', [
            'name' => 'Muammar K', 'company' => 'AmanahCorp',
            'email' => 'owner@amanah.co.id',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        return User::firstOrFail();
    }

    public function test_index_auto_creates_default_template(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/templates')->assertOk();

        $workspace = $owner->currentWorkspace();
        $tpl = DocTemplate::where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertTrue($tpl->is_default);
        $this->assertSame(config('spekta.doc_sets.3'), $tpl->doc_kinds);
    }

    public function test_admin_creates_and_updates_template(): void
    {
        $owner = $this->owner();
        $workspace = $owner->currentWorkspace();

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@amanah.co.id', 'password' => bcrypt('secret123')]);
        $workspace->members()->create(['user_id' => $admin->id, 'role' => 'admin']);

        $this->actingAs($admin)->post('/templates', [
            'name' => 'Proposal Ringkas', 'doc_kinds' => ['PRD', 'ROADMAP'], 'language' => 'id', 'tone' => 'formal',
        ])->assertSessionHasNoErrors();

        $tpl = DocTemplate::where('workspace_id', $workspace->id)->where('name', 'Proposal Ringkas')->firstOrFail();
        $this->assertFalse($tpl->is_default);

        $this->actingAs($admin)->post("/templates/{$tpl->id}", [
            'name' => 'Proposal Lengkap', 'config' => ['white_label' => '1'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Proposal Lengkap', $tpl->fresh()->name);
        $this->assertTrue($tpl->fresh()->config['white_label']);

        // Jadikan default — default lama turun otomatis
        $this->actingAs($admin)->post("/templates/{$tpl->id}/default")->assertSessionHasNoErrors();
        $this->assertTrue($tpl->fresh()->is_default);
        $this->assertSame(1, DocTemplate::where('workspace_id', $workspace->id)->where('is_default', true)->count());
    }

    public function test_member_cannot_update_template(): void
    {
        $owner = $this->owner();
        $workspace = $owner->currentWorkspace();

        $member = User::create(['name' => 'Member', 'email' => 'member@amanah.co.id', 'password' => bcrypt('secret123')]);
        $workspace->members()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($owner)->get('/templates'); // pastikan template default ada
        $tpl = DocTemplate::where('workspace_id', $workspace->id)->firstOrFail();

        $this->actingAs($member)->post("/templates/{$tpl->id}", ['name' => 'Hack'])->assertForbidden();
    }

    public function test_default_template_cannot_be_deleted(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get('/templates');
        $tpl = DocTemplate::where('workspace_id', $owner->currentWorkspace()->id)->firstOrFail();

        $this->actingAs($owner)->delete("/templates/{$tpl->id}")->assertSessionHasErrors('template');
        $this->assertNotNull($tpl->fresh());
    }

    public function test_new_project_gets_default_template(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/projects');

        $project = \App\Models\Project::firstOrFail();
        $default = DocTemplate::where('workspace_id', $owner->currentWorkspace()->id)
            ->where('is_default', true)->firstOrFail();
        $this->assertSame($default->id, $project->doc_template_id);
    }

    public function test_pipeline_uses_template_doc_kinds_when_opted_in(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $owner = $this->owner();
        $this->actingAs($owner)->post('/projects');

        $project = \App\Models\Project::firstOrFail();
        $project->docTemplate->update(['doc_kinds' => ['PRD', 'REQUIREMENTS']]);
        $project->update(['blueprint' => ['template' => 'workspace', 'depth' => 'auto']]);

        $run = app(\App\Services\GenerationPipeline::class)->start($project->fresh());

        $this->assertEqualsCanonicalizing(['PRD', 'REQUIREMENTS'], $run->nodes()->pluck('doc_key')->all());
    }
}
