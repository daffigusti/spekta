<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'company' => 'Test Corp',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_provisions_workspace_completely()
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'company' => 'Test Corp',
        ]);

        $user = User::firstOrFail();
        $workspace = $user->currentWorkspace();

        $this->assertNotNull($workspace);
        $this->assertSame('Test Corp', $workspace->name);
        $this->assertSame($workspace->id, $user->current_workspace_id);
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('subscriptions', ['workspace_id' => $workspace->id, 'plan' => 'free', 'status' => 'active']);
        $this->assertDatabaseHas('credit_ledger', ['workspace_id' => $workspace->id, 'kind' => 'plan_grant']);
        $this->assertDatabaseHas('rate_cards', ['workspace_id' => $workspace->id, 'is_default' => true]);
    }
}
