<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    /** Registrasi memprovision workspace + owner member; set paket agar batas anggota longgar. */
    private function ownerOnPlan(string $plan = 'pro'): User
    {
        $this->post('/register', [
            'name' => 'Muammar K', 'company' => 'AmanahCorp',
            'email' => 'owner@amanah.co.id',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $owner = User::firstOrFail();
        $owner->currentWorkspace()->subscription->update(['plan' => $plan]);

        return $owner;
    }

    public function test_owner_invites_new_email_creates_user_and_membership(): void
    {
        $owner = $this->ownerOnPlan('pro');
        $workspace = $owner->currentWorkspace();

        $this->actingAs($owner)->post('/team/members', [
            'email' => 'newbie@amanah.co.id',
            'role' => 'member',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'newbie@amanah.co.id']);
        $newUser = User::where('email', 'newbie@amanah.co.id')->firstOrFail();
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $newUser->id,
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.invited', 'workspace_id' => $workspace->id]);
    }

    public function test_invite_mixed_case_email_attaches_existing_user_without_duplicate(): void
    {
        $owner = $this->ownerOnPlan('pro');
        $workspace = $owner->currentWorkspace();

        $existing = User::create(['name' => 'Member', 'email' => 'member@amanah.co.id', 'password' => bcrypt('secret123')]);

        $this->actingAs($owner)->post('/team/members', [
            'email' => 'Member@Amanah.CO.ID',
            'role' => 'member',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, User::count()); // tidak ada akun duplikat beda kapitalisasi
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $existing->id,
            'role' => 'member',
        ]);
    }

    public function test_member_role_forbidden_to_invite(): void
    {
        $owner = $this->ownerOnPlan('pro');
        $workspace = $owner->currentWorkspace();

        $member = User::create(['name' => 'Member', 'email' => 'member@amanah.co.id', 'password' => bcrypt('secret123')]);
        $workspace->members()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($member)->post('/team/members', [
            'email' => 'someone@amanah.co.id',
            'role' => 'member',
        ])->assertForbidden();
    }

    public function test_cannot_demote_last_owner(): void
    {
        $owner = $this->ownerOnPlan('pro');
        $workspace = $owner->currentWorkspace();
        $ownerMember = $workspace->members()->where('role', 'owner')->firstOrFail();

        $this->actingAs($owner)->patch("/team/members/{$ownerMember->id}", ['role' => 'member'])
            ->assertStatus(422);

        $this->assertSame('owner', $ownerMember->fresh()->role);
    }

    public function test_member_limit_enforced_on_free_plan(): void
    {
        $owner = $this->ownerOnPlan('free'); // free = batas 1 anggota (owner sudah mengisi)
        $workspace = $owner->currentWorkspace();

        $this->actingAs($owner)->post('/team/members', [
            'email' => 'overflow@amanah.co.id',
            'role' => 'member',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'overflow@amanah.co.id']);
        $this->assertSame(1, $workspace->members()->count());
    }

    public function test_remove_member_works_and_logs_audit(): void
    {
        $owner = $this->ownerOnPlan('pro');
        $workspace = $owner->currentWorkspace();

        $member = User::create(['name' => 'Member', 'email' => 'member@amanah.co.id', 'password' => bcrypt('secret123')]);
        $pivot = $workspace->members()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($owner)->delete("/team/members/{$pivot->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('workspace_members', ['id' => $pivot->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.removed', 'workspace_id' => $workspace->id]);
    }
}
