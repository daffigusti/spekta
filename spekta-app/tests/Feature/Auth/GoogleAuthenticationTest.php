<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Socialite;
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

        $this->expectException(UniqueConstraintViolationException::class);

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

    public function test_google_callback_logs_in_existing_linked_user(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com', 'google_id' => 'google-123']);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-123',
            'name' => 'Owner Google',
            'email' => 'OWNER@example.com',
            'email_verified' => true,
        ]));

        $response = $this->get(route('google.callback'))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('google-123', $user->fresh()->google_id);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertFalse(collect($response->headers->getCookies())
            ->contains(fn ($cookie) => $cookie->getName() === Auth::getRecallerName()));
    }

    public function test_google_callback_provisions_new_user_workspace_and_free_plan(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-456',
            'name' => 'Google Owner',
            'email' => 'NEW@example.com',
            'email_verified' => true,
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
            'email_verified' => false,
        ]));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('status', 'Email Google harus terverifikasi untuk digunakan.');

        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
    }

    public function test_google_login_rejects_existing_unlinked_email_without_mutation(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com', 'google_id' => null]);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-existing', 'email' => 'existing@example.com', 'email_verified' => true,
        ]));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('status', 'Masuk dengan kata sandi lalu hubungkan Google dari Pengaturan.');

        $this->assertSame(null, $user->fresh()->google_id);
        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_google_login_never_replaces_existing_google_identity(): void
    {
        $user = User::factory()->create(['google_id' => 'google-original']);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-other', 'email' => $user->email, 'email_verified' => true,
        ]));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login', absolute: false));

        $this->assertSame('google-original', $user->fresh()->google_id);
    }

    public function test_google_login_rejects_conflicting_identity_and_email_mappings(): void
    {
        $identityOwner = User::factory()->create(['email' => 'identity@example.com', 'google_id' => 'google-conflict']);
        $emailOwner = User::factory()->create(['email' => 'email@example.com', 'google_id' => null]);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-conflict', 'email' => $emailOwner->email, 'email_verified' => true,
        ]));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('status', 'Login Google tidak dapat dilanjutkan. Silakan coba lagi.');

        $this->assertGuest();
        $this->assertSame('google-conflict', $identityOwner->fresh()->google_id);
    }

    public function test_repeated_google_callback_keeps_single_workspace(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'repeat-google', 'email' => 'repeat@example.com', 'email_verified' => true,
        ]));

        $this->get(route('google.callback'))
            ->assertRedirect(route('dashboard', absolute: false));
        $this->post(route('logout'));

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'repeat-google', 'email' => 'repeat@example.com', 'email_verified' => true,
        ]));
        $this->get(route('google.callback'))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs(User::where('google_id', 'repeat-google')->firstOrFail());
        $this->assertSame(1, User::where('google_id', 'repeat-google')->count());
        $this->assertDatabaseCount('workspaces', 1);
    }

    public function test_google_login_requires_canonical_boolean_email_verified_claim(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-legacy-claim', 'email' => 'legacy@example.com',
            'verified_email' => true, 'email_verified' => 'true',
        ]));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('status', 'Email Google harus terverifikasi untuk digunakan.');
    }

    public function test_google_callback_handles_invalid_state_safely(): void
    {
        $this->get(route('google.redirect'));

        $this->withSession(['state' => 'not-the-provider-state'])
            ->get(route('google.callback').'?state=wrong&code=fake')
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('status', 'Login Google tidak dapat dilanjutkan. Silakan coba lagi.')
            ->assertSessionMissing('exception');
    }
}
