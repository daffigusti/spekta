<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceSwitchTest extends TestCase
{
    use RefreshDatabase;

    /** Register via endpoint supaya provision jalan penuh. */
    private function registerOwner(string $email, string $company): User
    {
        $this->post('/register', [
            'name' => ucfirst(explode('@', $email)[0]),
            'company' => $company,
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        auth()->logout();

        return User::where('email', $email)->firstOrFail();
    }

    public function test_member_can_switch_and_selection_persists(): void
    {
        $alice = $this->registerOwner('alice@a.co', 'Alpha');
        $bob = $this->registerOwner('bob@b.co', 'Beta');
        $beta = $bob->currentWorkspace();
        $beta->members()->create(['user_id' => $alice->id, 'role' => 'member']);

        $this->actingAs($alice)
            ->post('/workspace/switch', ['workspace_id' => $beta->id])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame($beta->id, $alice->fresh()->current_workspace_id);

        $this->actingAs($alice->fresh())->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('workspace.id', $beta->id)->where('workspace.name', 'Beta'));
    }

    public function test_non_member_cannot_switch(): void
    {
        $alice = $this->registerOwner('alice@a.co', 'Alpha');
        $bob = $this->registerOwner('bob@b.co', 'Beta');

        $this->actingAs($alice)
            ->post('/workspace/switch', ['workspace_id' => $bob->currentWorkspace()->id])
            ->assertForbidden();

        $this->assertSame($alice->currentWorkspace()->id, $alice->fresh()->currentWorkspace()->id);
    }

    public function test_stale_pointer_falls_back_to_oldest_membership(): void
    {
        $alice = $this->registerOwner('alice@a.co', 'Alpha');
        $alpha = $alice->currentWorkspace();
        $bob = $this->registerOwner('bob@b.co', 'Beta');
        $beta = $bob->currentWorkspace();
        $pivot = $beta->members()->create(['user_id' => $alice->id, 'role' => 'member']);

        $alice->current_workspace_id = $beta->id;
        $alice->save();

        // Dikeluarkan dari Beta → pointer stale → fallback membership tertua (Alpha), bukan 500
        $pivot->delete();

        $this->assertSame($alpha->id, $alice->fresh()->currentWorkspace()->id);
        $this->actingAs($alice->fresh())->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.id', $alpha->id));
    }

    public function test_user_without_workspace_gets_provisioned_by_middleware(): void
    {
        $orphan = User::create(['name' => 'Orphan', 'email' => 'orphan@x.co', 'password' => bcrypt('secret123')]);

        // Sebelumnya 500 (TeamController::memberFor menerima null Workspace)
        $this->actingAs($orphan)->get('/team')->assertOk();

        $workspace = $orphan->fresh()->currentWorkspace();
        $this->assertNotNull($workspace);
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $orphan->id, 'role' => 'owner']);
        $this->assertDatabaseHas('subscriptions', ['workspace_id' => $workspace->id]);
    }

    public function test_shared_props_expose_all_memberships(): void
    {
        $alice = $this->registerOwner('alice@a.co', 'Alpha');
        $bob = $this->registerOwner('bob@b.co', 'Beta');
        $bob->currentWorkspace()->members()->create(['user_id' => $alice->id, 'role' => 'member']);

        $this->actingAs($alice)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->has('workspaces', 2)
                ->where('workspaces.0.name', 'Alpha')
                ->where('workspaces.0.role', 'owner')
                ->where('workspaces.1.name', 'Beta')
                ->where('workspaces.1.role', 'member'));
    }
}
