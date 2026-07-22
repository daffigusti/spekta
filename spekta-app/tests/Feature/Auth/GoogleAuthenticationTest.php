<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\GoogleAuthenticatedSessionController;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceProvisioner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $this->assertSame('http://localhost/settings/profile/google/callback', config('services.google.link_redirect'));
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
        $this->assertSame(0, User::where('google_id', 'google-existing')->count());
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

    public function test_google_login_uses_google_identity_when_email_maps_to_another_user(): void
    {
        $identityOwner = User::factory()->create(['email' => 'identity@example.com', 'google_id' => 'google-conflict']);
        $emailOwner = User::factory()->create(['email' => 'email@example.com', 'google_id' => null]);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-conflict', 'email' => $emailOwner->email, 'email_verified' => true,
        ]));

        $this->get(route('google.callback'))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($identityOwner->fresh());
        $this->assertSame('google-conflict', $identityOwner->fresh()->google_id);
        $this->assertNull($emailOwner->fresh()->google_id);
    }

    public function test_google_login_recovers_committed_postgres_creation_race(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for independent connection concurrency coverage.');
        }

        try {
            DB::select('select 1');
        } catch (\Throwable $exception) {
            $this->markTestSkipped('PostgreSQL is unavailable.');
        }

        // RefreshDatabase keeps one connection transaction open. Close it before fork so
        // both processes create genuinely independent PostgreSQL sessions.
        DB::rollBack();
        DB::disconnect();

        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Could not fork PostgreSQL race process.');
        }

        if ($pid === 0) {
            fclose($reader);
            config(['database.connections.oauth_race' => config('database.connections.pgsql')]);
            DB::setDefaultConnection('oauth_race');

            DB::beginTransaction();
            $winner = User::create([
                'name' => 'Committed Winner',
                'email' => 'collision@example.com',
                'google_id' => 'collision-google',
                'password' => Hash::make('collision-password'),
            ]);
            $winner->forceFill(['email_verified_at' => now()])->save();
            $workspace = app(WorkspaceProvisioner::class)->provision($winner, 'Workspace Committed Winner');
            $winner->forceFill(['current_workspace_id' => $workspace->id])->save();

            // Do not commit until parent has entered createGoogleUser. This
            // proves recovery handles the unique violation, rather than merely
            // logging in a row that was already committed before the attempt.
            fwrite($writer, 'r');
            if (fread($writer, 1) !== 'a') {
                DB::rollBack();
                exit(1);
            }
            DB::commit();
            fwrite($writer, 'c');
            fclose($writer);
            exit(0);
        }

        fclose($writer);
        try {
            $this->assertSame('r', fread($reader, 1));
            DB::reconnect();
            DB::beginTransaction();

            $this->app->instance(GoogleAuthenticatedSessionController::class, new class($reader) extends GoogleAuthenticatedSessionController
            {
                public function __construct(private $socket) {}

                protected function createGoogleUser($googleUser, string $email, string $googleId): User
                {
                    fwrite($this->socket, 'a');
                    if (fread($this->socket, 1) !== 'c') {
                        throw new \RuntimeException('OAuth race barrier failed.');
                    }

                    return parent::createGoogleUser($googleUser, $email, $googleId);
                }
            });

            Socialite::fake('google', SocialiteUser::fake([
                'id' => 'collision-google', 'email' => 'collision@example.com', 'email_verified' => true,
            ]));

            $this->get(route('google.callback'))
                ->assertRedirect(route('dashboard', absolute: false));

            pcntl_waitpid($pid, $status);

            $this->assertAuthenticatedAs(User::where('google_id', 'collision-google')->firstOrFail());
            $this->assertSame(1, User::where('google_id', 'collision-google')->count());
            $this->assertSame(1, DB::table('workspaces')->where('name', 'Workspace Committed Winner')->count());
        } finally {
            // Child committed outside RefreshDatabase's transaction. Roll back
            // parent first, then remove all child-owned durable rows.
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }

            $workspaceIds = Workspace::where('name', 'Workspace Committed Winner')->pluck('id');
            if ($workspaceIds->isNotEmpty()) {
                Workspace::whereKey($workspaceIds)->delete();
            }
            User::where('email', 'collision@example.com')->delete();

            fclose($reader);
        }
    }

    public function test_google_login_requires_canonical_boolean_email_verified_claim(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-legacy-claim', 'email' => 'legacy@example.com',
            'email_verified' => 'true',
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

    public function test_google_link_redirect_requires_authentication(): void
    {
        $this->get(route('google.link.redirect'))->assertRedirect(route('login', absolute: false));
        $this->get(route('google.link.callback'))->assertRedirect(route('login', absolute: false));
    }

    public function test_authenticated_user_can_start_google_link_and_session_binds_user(): void
    {
        $user = User::factory()->create(['google_id' => null]);
        Socialite::fake('google');

        $this->actingAs($user)
            ->get(route('google.link.redirect'))
            ->assertRedirect();

        $this->assertSame($user->id, session('google_link_user_id'));
    }

    public function test_google_link_rejects_missing_or_mismatched_session_user(): void
    {
        $user = User::factory()->create(['google_id' => null]);

        $this->actingAs($user)
            ->withSession(['google_link_user_id' => $user->id + 1])
            ->get(route('google.link.callback'))
            ->assertRedirect(route('profile.edit', absolute: false))
            ->assertSessionHas('status', 'Tautan Google tidak dapat dilanjutkan. Silakan mulai lagi dari Pengaturan.')
            ->assertSessionMissing('google_link_user_id');
    }

    public function test_google_link_rejects_missing_session_user_without_mutation_or_creation(): void
    {
        $user = User::factory()->create(['google_id' => null]);
        $userCount = User::query()->count();
        $workspaceCount = Workspace::query()->count();

        $this->actingAs($user)
            ->get(route('google.link.callback'))
            ->assertRedirect(route('profile.edit', absolute: false))
            ->assertSessionHas('status', 'Tautan Google tidak dapat dilanjutkan. Silakan mulai lagi dari Pengaturan.')
            ->assertSessionMissing('google_link_user_id');

        $this->assertNull($user->fresh()->google_id);
        $this->assertSame($userCount, User::query()->count());
        $this->assertSame($workspaceCount, Workspace::query()->count());
    }

    public function test_authenticated_user_can_link_google_identity(): void
    {
        $user = User::factory()->create(['google_id' => null]);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-linked', 'email' => 'linked@example.com', 'email_verified' => true,
        ]));

        $this->actingAs($user)
            ->withSession(['google_link_user_id' => $user->id])
            ->get(route('google.link.callback'))
            ->assertRedirect(route('profile.edit', absolute: false))
            ->assertSessionHas('status', 'Google berhasil terhubung.')
            ->assertSessionMissing('google_link_user_id');

        $this->assertSame('google-linked', $user->fresh()->google_id);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_google_link_rejects_identity_owned_by_another_user(): void
    {
        $user = User::factory()->create(['google_id' => null]);
        $owner = User::factory()->create(['google_id' => 'google-owned']);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => $owner->google_id, 'email' => 'other@example.com', 'email_verified' => true,
        ]));

        $this->actingAs($user)
            ->withSession(['google_link_user_id' => $user->id])
            ->get(route('google.link.callback'))
            ->assertRedirect(route('profile.edit', absolute: false))
            ->assertSessionHas('status', 'Google sudah terhubung ke akun lain.')
            ->assertSessionMissing('google_link_user_id');

        $this->assertNull($user->fresh()->google_id);
    }

    public function test_google_link_never_replaces_callers_existing_identity(): void
    {
        $user = User::factory()->create(['google_id' => 'google-original']);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-other', 'email' => 'other@example.com', 'email_verified' => true,
        ]));

        $this->actingAs($user)
            ->withSession(['google_link_user_id' => $user->id])
            ->get(route('google.link.callback'))
            ->assertRedirect(route('profile.edit', absolute: false))
            ->assertSessionHas('status', 'Akun ini sudah memiliki tautan Google.')
            ->assertSessionMissing('google_link_user_id');

        $this->assertSame('google-original', $user->fresh()->google_id);
    }

    public function test_google_link_rejects_unverified_email_and_clears_session(): void
    {
        $user = User::factory()->create(['google_id' => null]);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-unverified-link', 'email' => 'unverified@example.com', 'email_verified' => false,
        ]));

        $this->actingAs($user)
            ->withSession(['google_link_user_id' => $user->id])
            ->get(route('google.link.callback'))
            ->assertRedirect(route('profile.edit', absolute: false))
            ->assertSessionHas('status', 'Email Google harus terverifikasi untuk digunakan.')
            ->assertSessionMissing('google_link_user_id');

        $this->assertNull($user->fresh()->google_id);
    }
}
