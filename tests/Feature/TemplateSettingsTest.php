<?php

namespace Tests\Feature;

use App\Models\DocTemplate;
use App\Models\User;
use App\Models\Workspace;
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

    public function test_index_auto_creates_three_templates(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/templates')->assertOk();

        $workspace = $owner->currentWorkspace();
        $this->assertSame(3, DocTemplate::where('workspace_id', $workspace->id)->count());
        $this->assertEqualsCanonicalizing(
            ['proposal', 'document', 'portal'],
            DocTemplate::where('workspace_id', $workspace->id)->pluck('kind')->all(),
        );
    }

    public function test_admin_updates_proposal_config(): void
    {
        $owner = $this->owner();
        $workspace = $owner->currentWorkspace();

        // Buat admin di workspace yang sama
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@amanah.co.id', 'password' => bcrypt('secret123')]);
        $workspace->members()->create(['user_id' => $admin->id, 'role' => 'admin']);

        $this->actingAs($admin)->get('/templates'); // auto-create dulu
        $this->actingAs($admin)->post('/templates/proposal', [
            'config' => ['primary_color' => '#123456', 'show_cover' => false, 'page_format' => 'Letter'],
        ])->assertSessionHasNoErrors();

        $tpl = DocTemplate::where('workspace_id', $workspace->id)->where('kind', 'proposal')->firstOrFail();
        $this->assertSame('#123456', $tpl->fresh()->config['primary_color']);
        $this->assertFalse($tpl->fresh()->config['show_cover']);
        $this->assertSame('Letter', $tpl->fresh()->config['page_format']);
    }

    public function test_member_cannot_update_template(): void
    {
        $owner = $this->owner();
        $workspace = $owner->currentWorkspace();

        $member = User::create(['name' => 'Member', 'email' => 'member@amanah.co.id', 'password' => bcrypt('secret123')]);
        $workspace->members()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($owner)->get('/templates'); // pastikan template ada
        $this->actingAs($member)->post('/templates/proposal', [
            'config' => ['primary_color' => '#000000'],
        ])->assertForbidden();
    }
}
