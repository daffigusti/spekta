# Google Login Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pengguna dapat masuk dengan Google; akun baru otomatis memperoleh workspace dan benefit free seperti registrasi biasa.

**Architecture:** Laravel Socialite menangani redirect OAuth berbasis session dan callback. `GoogleAuthenticatedSessionController` menjadi boundary provider: validasi profil Google, cari/tautkan user, atau buat user bersama workspace secara atomik menggunakan `WorkspaceProvisioner`; UI hanya mengarahkan browser ke endpoint redirect.

**Tech Stack:** Laravel 13, Laravel Socialite, Inertia 2, React 19, PHPUnit 11, PostgreSQL/SQLite test database.

---

## File structure

- `spekta-app/composer.json`, `spekta-app/composer.lock` — dependensi Socialite.
- `spekta-app/config/services.php` — konfigurasi provider Google dari environment.
- `spekta-app/database/migrations/YYYY_MM_DD_XXXXXX_add_google_id_to_users_table.php` — tautan OAuth unik dan nullable.
- `spekta-app/app/Models/User.php` — `google_id` sebagai atribut mass-assignable.
- `spekta-app/app/Http/Controllers/Auth/GoogleAuthenticatedSessionController.php` — redirect, callback, penanganan error, dan provisioning.
- `spekta-app/routes/auth.php` — rute guest Google OAuth.
- `spekta-app/resources/js/layouts/auth/spekta-shell.tsx` — tombol Google aktif dan berupa link aman menuju route backend.
- `spekta-app/tests/Feature/Auth/GoogleAuthenticationTest.php` — cakupan redirect, login user lama, provisioning user baru, dan callback gagal.
- `spekta-app/env.spekta.example` — nama environment variable OAuth tanpa nilai secret.

### Task 1: Tambah dependensi dan storage identitas Google

**Files:**
- Modify: `spekta-app/composer.json`
- Modify: `spekta-app/composer.lock`
- Modify: `spekta-app/config/services.php`
- Create: `spekta-app/database/migrations/YYYY_MM_DD_XXXXXX_add_google_id_to_users_table.php`
- Modify: `spekta-app/app/Models/User.php:21-25`
- Modify: `spekta-app/env.spekta.example`
- Test: `spekta-app/tests/Feature/Auth/GoogleAuthenticationTest.php`

- [ ] **Step 1: Write failing schema/configuration tests**

```php
public function test_google_provider_configuration_uses_environment_variables(): void
{
    config([
        'services.google.client_id' => 'client-id',
        'services.google.client_secret' => 'client-secret',
        'services.google.redirect' => 'http://localhost/auth/google/callback',
    ]);

    $this->assertSame('client-id', config('services.google.client_id'));
    $this->assertSame('client-secret', config('services.google.client_secret'));
    $this->assertSame('http://localhost/auth/google/callback'), config('services.google.redirect'));
}

public function test_google_id_must_be_unique(): void
{
    User::factory()->create(['google_id' => 'google-123']);

    $this->expectException(QueryException::class);
    User::factory()->create(['google_id' => 'google-123']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GoogleAuthenticationTest`

Expected: FAIL because `services.google` and `users.google_id` do not exist.

- [ ] **Step 3: Install Socialite**

Run: `composer require laravel/socialite`

Expected: `laravel/socialite` appears in `composer.json` and Composer updates `composer.lock` without removing existing dependencies.

- [ ] **Step 4: Add configuration, migration, model attribute, and environment template**

Add to `config/services.php`:

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),
    'scopes' => ['openid', 'profile', 'email'],
],
```

Generate migration with `php artisan make:migration add_google_id_to_users_table`, then implement:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('google_id')->nullable()->unique()->after('email');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropUnique(['google_id']);
        $table->dropColumn('google_id');
    });
}
```

Extend `User::$fillable` with `'google_id'`. Add blank-key documentation only to `env.spekta.example`:

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
GOOGLE_LINK_REDIRECT_URI="${APP_URL}/settings/profile/google/callback"
```

- [ ] **Step 5: Run focused test to verify it passes**

Run: `php artisan test --filter=GoogleAuthenticationTest`

Expected: PASS for provider configuration and unique Google identity.

- [ ] **Step 6: Commit storage and dependency change**

```bash
git add composer.json composer.lock config/services.php database/migrations app/Models/User.php env.spekta.example tests/Feature/Auth/GoogleAuthenticationTest.php
git commit -m "feat: add Google OAuth identity storage"
```

### Task 2: Implement Google OAuth controller and guest routes

**Files:**
- Create: `spekta-app/app/Http/Controllers/Auth/GoogleAuthenticatedSessionController.php`
- Modify: `spekta-app/routes/auth.php:3-22`
- Modify: `spekta-app/tests/Feature/Auth/GoogleAuthenticationTest.php`
- Test: `spekta-app/tests/Feature/Auth/GoogleAuthenticationTest.php`

- [ ] **Step 1: Write failing OAuth flow tests**

```php
public function test_google_redirect_starts_oauth_flow(): void
{
    Socialite::fake('google');

    $this->get(route('google.redirect'))->assertRedirect();
}

public function test_google_callback_logs_in_existing_user_and_links_google_identity(): void
{
    $user = User::factory()->create(['email' => 'owner@example.com', 'google_id' => null]);
    Socialite::fake('google', User::fake([
        'id' => 'google-123', 'name' => 'Owner Google', 'email' => 'owner@example.com', 'verified_email' => true,
    ]));

    $this->get(route('google.callback'))
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user->fresh());
    $this->assertSame('google-123', $user->fresh()->google_id);
    $this->assertDatabaseCount('workspaces', 0);
}

public function test_google_callback_provisions_new_user_workspace_and_free_plan(): void
{
    Socialite::fake('google', User::fake([
        'id' => 'google-456', 'name' => 'Google Owner', 'email' => 'new@example.com', 'verified_email' => true,
    ]));

    $this->get(route('google.callback'))
        ->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'new@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    $this->assertSame('google-456', $user->google_id);
    $this->assertNotNull($user->currentWorkspace());
    $this->assertSame('free', $user->currentWorkspace()->subscription->plan);
    $this->assertSame(2.0, $user->currentWorkspace()->creditBalance());
}

public function test_google_callback_rejects_an_unverified_email(): void
{
    Socialite::fake('google', User::fake([
        'id' => 'google-unverified', 'name' => 'Unverified User', 'email' => 'unverified@example.com', 'verified_email' => false,
    ]));

    $this->get(route('google.callback'))
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHas('status', 'Email Google harus terverifikasi untuk digunakan.');

    $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=GoogleAuthenticationTest`

Expected: FAIL because Google routes and controller do not exist.

- [ ] **Step 3: Implement controller with stateful Socialite flow**

Create `GoogleAuthenticatedSessionController` with this behavior:

```php
public function redirect(): RedirectResponse
{
    return Socialite::driver('google')->redirect();
}

public function callback(Request $request): RedirectResponse
{
    try {
        $googleUser = Socialite::driver('google')->user();
    } catch (InvalidStateException|DriverMissingConfigurationException $exception) {
        report($exception);

        return to_route('login')->with('status', 'Login Google tidak dapat dilanjutkan. Silakan coba lagi.');
    } catch (Throwable $exception) {
        report($exception);

        return to_route('login')->with('status', 'Login Google sedang bermasalah. Silakan coba lagi.');
    }

    $email = Str::lower((string) $googleUser->getEmail());
    $googleId = (string) $googleUser->getId();
    $emailVerified = (bool) data_get($googleUser->user, 'verified_email');

    if ($email === '' || $googleId === '') {
        return to_route('login')->with('status', 'Google tidak memberikan email akun yang dapat digunakan.');
    }

    if (! $emailVerified) {
        return to_route('login')->with('status', 'Email Google harus terverifikasi untuk digunakan.');
    }

    $user = DB::transaction(function () use ($googleUser, $googleId, $email) {
        $user = User::where('google_id', $googleId)->first()
            ?? User::where('email', $email)->first();

        if ($user) {
            $user->forceFill(['google_id' => $googleId])->save();

            return $user;
        }

        $user = User::create([
            'name' => $googleUser->getName() ?: $email,
            'email' => $email,
            'google_id' => $googleId,
            'email_verified_at' => now(),
            'password' => Str::random(64),
        ]);
        $workspace = app(WorkspaceProvisioner::class)->provision($user, "Workspace {$user->name}");
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return $user;
    });

    Auth::login($user, true);
    $request->session()->regenerate();

    return redirect()->intended(route('dashboard', absolute: false));
}
```

Use fully qualified imports for `WorkspaceProvisioner`, `Socialite`, `InvalidStateException`, `DriverMissingConfigurationException`, `Throwable`, `DB`, `Auth`, and `Str`. Keep default state verification; do not use `stateless()` for web login.

In the test file, import `App\Models\User` as `User` and `Laravel\Socialite\Two\User` as `SocialiteUser`, then call `SocialiteUser::fake(...)` so the two user classes are unambiguous.

- [ ] **Step 4: Register guest routes**

Add inside existing `Route::middleware('guest')->group()` in `routes/auth.php`:

```php
Route::get('auth/google', [GoogleAuthenticatedSessionController::class, 'redirect'])
    ->name('google.redirect');
Route::get('auth/google/callback', [GoogleAuthenticatedSessionController::class, 'callback'])
    ->name('google.callback');
```

- [ ] **Step 5: Run focused OAuth tests**

Run: `php artisan test --filter=GoogleAuthenticationTest`

Expected: PASS for redirect, existing-account link/login, and new-account provisioning.

- [ ] **Step 6: Commit backend OAuth flow**

```bash
git add app/Http/Controllers/Auth/GoogleAuthenticatedSessionController.php routes/auth.php tests/Feature/Auth/GoogleAuthenticationTest.php
git commit -m "feat: add Google OAuth login flow"
```

### Task 3: Activate Google entry points and test failure handling

**Files:**
- Modify: `spekta-app/resources/js/layouts/auth/spekta-shell.tsx:229-280`
- Modify: `spekta-app/tests/Feature/Auth/GoogleAuthenticationTest.php`
- Test: `spekta-app/tests/Feature/Auth/GoogleAuthenticationTest.php`

- [ ] **Step 1: Write failing cancellation/failure tests**

```php
public function test_google_callback_failure_returns_to_login_with_safe_message(): void
{
    Socialite::shouldReceive('driver->user')
        ->once()
        ->andThrow(new InvalidStateException('state mismatch'));

    $this->get(route('google.callback'))
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHas('status', 'Login Google tidak dapat dilanjutkan. Silakan coba lagi.');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GoogleAuthenticationTest`

Expected: FAIL until callback handles provider state failure.

- [ ] **Step 3: Activate existing Google button without changing visual design**

Replace disabled `<button>` in `GoogleDivider` with an anchor that preserves existing layout, SVG, spacing, colors, and label:

```tsx
<a
    href={route('google.redirect')}
    style={{
        width: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 10,
        background: '#fff', border: 'none', color: '#1F2937', borderRadius: 11, padding: '12px 0',
        fontSize: 13.5, fontWeight: 700, marginTop: 22, boxSizing: 'border-box', textDecoration: 'none',
    }}
>
    {/* retain existing Google SVG */}
    Lanjutkan dengan Google
</a>
```

Remove disabled-only `title`, `cursor`, opacity, and the `SEGERA` badge. Both login and registration pages already render `GoogleDivider`, so no page-level change is needed.

- [ ] **Step 4: Run focused backend tests and frontend checks**

Run: `php artisan test --filter=GoogleAuthenticationTest && npm run lint && npm run format`

Expected: All Google flow tests pass; lint and formatter finish successfully.

- [ ] **Step 5: Run regression suite and inspect route registration**

Run: `php artisan test --filter='(AuthenticationTest|RegistrationTest|MvpFlowTest|GoogleAuthenticationTest)' && php artisan route:list --name=google`

Expected: Selected tests PASS; route list shows `google.redirect` and `google.callback` as GET guest routes.

- [ ] **Step 6: Commit UI and failure coverage**

```bash
git add resources/js/layouts/auth/spekta-shell.tsx tests/Feature/Auth/GoogleAuthenticationTest.php
git commit -m "feat: activate Google login button"
```

### Task 4: Configure Google Cloud and manually verify real OAuth

**Files:**
- Modify: deployment environment only; do not commit secrets.

- [ ] **Step 1: Create OAuth client in Google Cloud Console**

Create a **Web application** OAuth client. Register both authorized redirect URIs. Each URI must match the application callback **exactly**, including protocol, host, port, path, and trailing slash.

```text
http://localhost/auth/google/callback
http://localhost/settings/profile/google/callback
```

For production, register both equivalent production URIs:

```text
https://<deployment-host>/auth/google/callback
https://<deployment-host>/settings/profile/google/callback
```

- [ ] **Step 2: Set runtime configuration**

Set these values in local and deployed environment secret stores:

```dotenv
GOOGLE_CLIENT_ID=<Google OAuth client ID>
GOOGLE_CLIENT_SECRET=<Google OAuth client secret>
GOOGLE_REDIRECT_URI=https://<deployment-host>/auth/google/callback
GOOGLE_LINK_REDIRECT_URI=https://<deployment-host>/settings/profile/google/callback
```

Do not store actual secrets in source control, documentation, test fixtures, or chat.

- [ ] **Step 3: Clear cached configuration and perform manual browser check**

Run: `php artisan config:clear`

Expected: Command reports configuration cache cleared. Open login page, select Google, complete consent with a new Google account, and confirm dashboard opens with a free workspace and 2 credits. Repeat with an existing email/password account; confirm no second workspace is created.

- [ ] **Step 4: Commit only source-controlled documentation changes if any**

```bash
git status --short
```

Expected: No `.env` or secret file is staged. Do not create a commit solely for external console/environment setup.

## Self-review

- Coverage: Socialite dependency, environment-only configuration, Google ID persistence, stateful redirect/callback, existing-user linking, new-user workspace provisioning, safe error UI, automated tests, and real-console setup each have a task.
- Placeholder scan: no red-flag placeholder or unspecified test action remains.
- Consistency: routes use `google.redirect` and `google.callback` throughout; storage field is `google_id`; new workspace name explicitly defaults to `Workspace {Google name}` because Google OAuth provides no company field.

## Security amendment: authenticated linking for existing accounts

This amendment supersedes every Task 2/3 instruction that matches an existing local user by Google email during **login**. It follows the approved policy: existing email/password users connect Google only after authenticating in Spekta.

### Task 2 replacement: Safe Google login callback

**Files:**
- Modify: `spekta-app/app/Http/Controllers/Auth/GoogleAuthenticatedSessionController.php`
- Modify: `spekta-app/tests/Feature/Auth/GoogleAuthenticationTest.php`

- [ ] **Step 1: Add failing boundary tests**

Test all cases below with canonical raw `email_verified: true|false`, never the deprecated `verified_email` claim:

```php
public function test_google_login_rejects_existing_unlinked_email(): void
{
    User::factory()->create(['email' => 'existing@example.com', 'google_id' => null]);
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-existing', 'email' => 'existing@example.com', 'email_verified' => true,
    ]));

    $this->get(route('google.callback'))
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHas('status', 'Masuk dengan kata sandi lalu hubungkan Google dari Pengaturan.');

    $this->assertDatabaseMissing('users', ['google_id' => 'google-existing']);
}

public function test_google_login_never_replaces_an_existing_google_identity(): void
{
    $user = User::factory()->create(['google_id' => 'google-original']);
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-other', 'email' => $user->email, 'email_verified' => true,
    ]));

    $this->get(route('google.callback'))->assertRedirect(route('login', absolute: false));
    $this->assertSame('google-original', $user->fresh()->google_id);
}
```

- [ ] **Step 2: Replace email auto-linking with explicit login policy**

Within one transaction, fetch `User::where('google_id', $googleId)->lockForUpdate()->first()` first. If found, login it. If no Google identity exists but `User::where('email', $email)->exists()`, return the safe password/settings status above; do not alter that user. Only create a new user when neither identity nor email exists. Catch a unique-constraint `QueryException` from creation, re-read both identifiers, and continue only if they now resolve to the same `google_id`; otherwise return the safe failure status. This ensures concurrent callbacks cannot provision duplicate workspaces or overwrite a link.

Use strict claim validation:

```php
$emailVerified = data_get($googleUser->user, 'email_verified') === true;
```

Use `Auth::login($user)` without a remember argument. Handle `InvalidStateException` separately without `report()`; retain generic exception reporting. Test the invalid state through the actual stateful callback path, not a facade-chain mock.

- [ ] **Step 3: Run and commit backend hardening**

Run: `php artisan test --filter=GoogleAuthenticationTest`

Expected: login callback tests PASS, including linked login, new user provisioning, unlinked local-email rejection, identity preservation, canonical verification, and invalid state.

```bash
git add app/Http/Controllers/Auth/GoogleAuthenticatedSessionController.php tests/Feature/Auth/GoogleAuthenticationTest.php
git commit -m "fix: secure Google OAuth account matching"
```

### Task 3 replacement: Authenticated Google linking in profile settings

**Files:**
- Modify: `spekta-app/app/Http/Controllers/Auth/GoogleAuthenticatedSessionController.php`
- Modify: `spekta-app/routes/auth.php`
- Modify: `spekta-app/app/Http/Controllers/Settings/ProfileController.php`
- Modify: `spekta-app/resources/js/pages/settings/profile.tsx`
- Modify: `spekta-app/tests/Feature/Auth/GoogleAuthenticationTest.php`
- Modify: `spekta-app/tests/Feature/Settings/ProfileUpdateTest.php`

- [ ] **Step 1: Add failing authenticated-link tests**

Cover: unauthenticated link redirect/callback is rejected; authenticated user can start link; successful callback sets only that user’s empty `google_id`; a Google ID already linked to another user is rejected; callback cannot replace caller’s non-null `google_id`; callback rejects unverified Google email; callback stores intended authenticated user ID in session and refuses a missing/mismatched session value.

- [ ] **Step 2: Implement link routes and callback ownership check**

Add authenticated routes:

```php
Route::get('settings/profile/google', [GoogleAuthenticatedSessionController::class, 'linkRedirect'])
    ->name('google.link.redirect');
Route::get('settings/profile/google/callback', [GoogleAuthenticatedSessionController::class, 'linkCallback'])
    ->name('google.link.callback');
```

`linkRedirect()` stores `auth()->id()` in a dedicated session key, then starts normal stateful Socialite redirect using `redirectUrl(config('services.google.link_redirect'))`. Add `link_redirect` to `services.google` and `GOOGLE_LINK_REDIRECT_URI` to the environment template. `linkCallback()` requires auth, verifies the stored ID equals current user ID, requires canonical `email_verified === true`, locks current user and any matching Google identity, then sets `google_id` only when current value is null and no other user owns it. Always regenerate session after successful link; clear the dedicated session key on every terminal path.

- [ ] **Step 3: Add profile affordance without changing unrelated visual design**

`ProfileController::edit()` passes `googleLinked` boolean. `settings/profile.tsx` adds a compact account-connection section below profile form: show “Google terhubung” when true; otherwise show “Hubungkan Google” link to `route('google.link.redirect')`. It must not expose Google IDs or email tokens.

- [ ] **Step 4: Run tests and commit**

Run: `php artisan test --filter='(GoogleAuthenticationTest|ProfileUpdateTest)' && npm run lint && npm run format`

Expected: tests, lint, and format PASS.

```bash
git add app/Http/Controllers/Auth/GoogleAuthenticatedSessionController.php routes/auth.php app/Http/Controllers/Settings/ProfileController.php resources/js/pages/settings/profile.tsx config/services.php env.spekta.example tests/Feature/Auth/GoogleAuthenticationTest.php tests/Feature/Settings/ProfileUpdateTest.php
git commit -m "feat: let users link Google from profile settings"
```
