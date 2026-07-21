<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_stores_unique_nullable_google_identity(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'google_id'));

        $user = User::factory()->create(['google_id' => 'google-user-123']);
        User::factory()->count(2)->create();

        $this->assertSame('google-user-123', $user->fresh()->google_id);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'google_id' => 'google-user-123',
        ]);
        $this->assertSame(2, User::query()->whereNull('google_id')->count());
        $this->assertDatabaseCount('users', 3);
    }

    public function test_google_identity_is_unique(): void
    {
        User::factory()->create(['google_id' => 'google-user-123']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        User::factory()->create(['google_id' => 'google-user-123']);
    }

    public function test_google_socialite_configuration_is_present(): void
    {
        $this->assertSame('test-google-client-id', config('services.google.client_id'));
        $this->assertSame('test-google-client-secret', config('services.google.client_secret'));
        $this->assertSame('http://localhost/auth/google/callback', config('services.google.redirect'));
        $this->assertSame(['openid', 'profile', 'email'], config('services.google.scopes'));
    }

    public function test_google_redirect_starts_oauth_flow(): void
    {
        Socialite::fake('google');

        $this->get(route('google.redirect'))->assertRedirect();
    }

    public function test_google_callback_logs_in_existing_user_and_links_google_identity(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com', 'google_id' => null]);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-123',
            'name' => 'Owner Google',
            'email' => 'OWNER@example.com',
            'verified_email' => true,
        ]));

        $this->get(route('google.callback'))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('google-123', $user->fresh()->google_id);
        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_google_callback_provisions_new_user_workspace_and_free_plan(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-456',
            'name' => 'Google Owner',
            'email' => 'NEW@example.com',
            'verified_email' => true,
        ]));

        $this->get(route('google.callback'))
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-456', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertNotNull($user->currentWorkspace());
        $this->assertSame('Workspace Google Owner', $user->currentWorkspace()->name);
        $this->assertSame('free', $user->currentWorkspace()->subscription->plan);
        $this->assertSame(2.0, $user->currentWorkspace()->creditBalance());
    }

    public function test_google_callback_rejects_an_unverified_email(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-unverified',
            'name' => 'Unverified User',
            'email' => 'unverified@example.com',
            'verified_email' => false,
        ]));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('status', 'Email Google harus terverifikasi untuk digunakan.');

        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
    }

    public function test_google_callback_handles_invalid_state_safely(): void
    {
        Socialite::shouldReceive('driver->user')
            ->once()
            ->andThrow(new InvalidStateException('state mismatch'));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('status', 'Login Google tidak dapat dilanjutkan. Silakan coba lagi.')
            ->assertSessionMissing('exception');
    }
}
