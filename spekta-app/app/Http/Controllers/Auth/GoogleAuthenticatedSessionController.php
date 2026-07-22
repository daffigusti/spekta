<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WorkspaceProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Socialite\Exceptions\DriverMissingConfigurationException;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleAuthenticatedSessionController extends Controller
{
    private const LINK_SESSION_KEY = 'google_link_user_id';

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function linkRedirect(Request $request)
    {
        $request->session()->put(self::LINK_SESSION_KEY, $request->user()->getAuthIdentifier());

        $providerRedirect = Socialite::driver('google')
            ->redirectUrl(config('services.google.link_redirect'))
            ->redirect();

        return Inertia::location($providerRedirect);
    }

    public function linkCallback(Request $request): RedirectResponse
    {
        $expectedUserId = $request->session()->pull(self::LINK_SESSION_KEY);
        $currentUser = $request->user();

        if ($expectedUserId === null || (string) $expectedUserId !== (string) $currentUser->getAuthIdentifier()) {
            return to_route('profile.edit')->with('googleStatus', 'Tautan Google tidak dapat dilanjutkan. Silakan mulai lagi dari Pengaturan.');
        }

        try {
            $googleUser = Socialite::driver('google')
                ->redirectUrl(config('services.google.link_redirect'))
                ->user();
        } catch (InvalidStateException $exception) {
            return to_route('profile.edit')->with('googleStatus', 'Tautan Google tidak dapat dilanjutkan. Silakan coba lagi.');
        } catch (DriverMissingConfigurationException $exception) {
            report($exception);

            return to_route('profile.edit')->with('googleStatus', 'Tautan Google sedang bermasalah. Silakan coba lagi.');
        } catch (Throwable $exception) {
            report($exception);

            return to_route('profile.edit')->with('googleStatus', 'Tautan Google sedang bermasalah. Silakan coba lagi.');
        }

        $googleId = (string) $googleUser->getId();

        if ($googleId === '') {
            return to_route('profile.edit')->with('googleStatus', 'Google tidak memberikan identitas akun yang dapat digunakan.');
        }

        if (data_get($googleUser->user, 'email_verified') !== true) {
            return to_route('profile.edit')->with('googleStatus', 'Email Google harus terverifikasi untuk digunakan.');
        }

        try {
            DB::transaction(function () use ($currentUser, $googleId): void {
                $user = User::whereKey($currentUser->getAuthIdentifier())->lockForUpdate()->firstOrFail();

                if ($user->google_id !== null) {
                    throw new \LogicException('Google identity already linked.');
                }

                $owner = User::where('google_id', $googleId)->lockForUpdate()->first();

                if ($owner !== null && (string) $owner->getAuthIdentifier() !== (string) $user->getAuthIdentifier()) {
                    throw new \DomainException('Google identity belongs to another user.');
                }

                $user->forceFill(['google_id' => $googleId])->save();
            });
        } catch (\DomainException) {
            return to_route('profile.edit')->with('googleStatus', 'Google sudah terhubung ke akun lain.');
        } catch (\LogicException) {
            return to_route('profile.edit')->with('googleStatus', 'Akun ini sudah memiliki tautan Google.');
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->isGoogleIdentityUniqueViolation($exception)) {
                return to_route('profile.edit')->with('googleStatus', 'Google sudah terhubung ke akun lain.');
            }

            report($exception);

            return to_route('profile.edit')->with('googleStatus', 'Tautan Google sedang bermasalah. Silakan coba lagi.');
        } catch (Throwable $exception) {
            report($exception);

            return to_route('profile.edit')->with('googleStatus', 'Tautan Google sedang bermasalah. Silakan coba lagi.');
        }

        $request->session()->regenerate();

        return to_route('profile.edit')->with('googleStatus', 'Google berhasil terhubung.');
    }

    private function isGoogleIdentityUniqueViolation(UniqueConstraintViolationException $exception): bool
    {
        return $exception->index === 'users_google_id_unique'
            && $exception->columns === ['google_id'];
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException $exception) {
            return to_route('login')->with('status', 'Login Google tidak dapat dilanjutkan. Silakan coba lagi.');
        } catch (DriverMissingConfigurationException $exception) {
            report($exception);

            return to_route('login')->with('status', 'Login Google tidak dapat dilanjutkan. Silakan coba lagi.');
        } catch (Throwable $exception) {
            report($exception);

            return to_route('login')->with('status', 'Login Google sedang bermasalah. Silakan coba lagi.');
        }

        $email = Str::lower((string) $googleUser->getEmail());
        $googleId = (string) $googleUser->getId();

        if ($email === '' || $googleId === '') {
            return to_route('login')->with('status', 'Google tidak memberikan email akun yang dapat digunakan.');
        }

        if (data_get($googleUser->user, 'email_verified') !== true) {
            return to_route('login')->with('status', 'Email Google harus terverifikasi untuk digunakan.');
        }

        try {
            $result = DB::transaction(function () use ($googleUser, $googleId, $email) {
                $identityOwner = User::where('google_id', $googleId)->lockForUpdate()->first();

                if ($identityOwner) {
                    return ['user' => $identityOwner];
                }

                $emailOwner = User::where('email', $email)->lockForUpdate()->first();

                if ($emailOwner) {
                    return ['status' => 'link_required'];
                }

                return ['user' => $this->createGoogleUser($googleUser, $email, $googleId)];
            });
        } catch (QueryException $exception) {
            try {
                if (! $this->isExpectedUserCreationRace($exception)) {
                    throw $exception;
                }

                $result = DB::transaction(function () use ($googleId, $email) {
                    $identityOwner = User::where('google_id', $googleId)->lockForUpdate()->first();

                    if ($identityOwner) {
                        // Never acquire the email-row lock after finding a mismatched
                        // identity. A concurrent request can hold those two rows in
                        // the opposite order.
                        return $identityOwner->email === $email
                            ? ['user' => $identityOwner]
                            : ['status' => 'conflict'];
                    }

                    $emailOwner = User::where('email', $email)->lockForUpdate()->first();

                    if ($emailOwner) {
                        return ['status' => 'conflict'];
                    }

                    return ['status' => 'conflict'];
                });
            } catch (QueryException $databaseException) {
                report($databaseException);

                return to_route('login')->with('status', 'Login Google sedang bermasalah. Silakan coba lagi.');
            }
        }

        if (($result['status'] ?? null) === 'link_required') {
            return to_route('login')->with('status', 'Masuk dengan kata sandi lalu hubungkan Google dari Pengaturan.');
        }

        if (($result['status'] ?? null) === 'conflict') {
            return to_route('login')->with('status', 'Login Google tidak dapat dilanjutkan. Silakan coba lagi.');
        }

        $user = $result['user'];

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function isExpectedUserCreationRace(QueryException $exception): bool
    {
        if (! $exception instanceof UniqueConstraintViolationException) {
            return false;
        }

        $columns = array_values($exception->columns);

        if (count($columns) !== 1 || ! in_array($columns[0], ['email', 'google_id'], true)) {
            return false;
        }

        // SQLite does not expose an index name. PostgreSQL does, and accepting
        // only these known users indexes prevents users_pkey/unrelated uniques
        // from being mistaken for an OAuth creation race.
        return $exception->index === null
            || in_array($exception->index, ['users_email_unique', 'users_google_id_unique'], true);
    }

    protected function createGoogleUser($googleUser, string $email, string $googleId): User
    {
        $user = User::create([
            'name' => $googleUser->getName() ?: $email,
            'email' => $email,
            'google_id' => $googleId,
            'password' => Hash::make(Str::random(64)),
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        $workspace = app(WorkspaceProvisioner::class)->provision($user, "Workspace {$user->name}");
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return $user;
    }
}
