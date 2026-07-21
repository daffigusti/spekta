<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_stores_unique_nullable_google_identity(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'google_id'));

        $user = User::factory()->create(['google_id' => 'google-user-123']);
        $userWithoutGoogleIdentity = User::factory()->create();

        $this->assertSame('google-user-123', $user->fresh()->google_id);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'google_id' => 'google-user-123',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $userWithoutGoogleIdentity->id,
            'google_id' => null,
        ]);
    }

    public function test_google_identity_is_unique(): void
    {
        User::factory()->create(['google_id' => 'google-user-123']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        User::factory()->create(['google_id' => 'google-user-123']);
    }

    public function test_google_socialite_configuration_is_present(): void
    {
        $this->assertSame(env('GOOGLE_CLIENT_ID'), config('services.google.client_id'));
        $this->assertSame(env('GOOGLE_CLIENT_SECRET'), config('services.google.client_secret'));
        $this->assertSame(env('GOOGLE_REDIRECT_URI', rtrim(env('APP_URL'), '/').'/auth/google/callback'), config('services.google.redirect'));
        $this->assertSame(['openid', 'profile', 'email'], config('services.google.scopes'));
    }
}
