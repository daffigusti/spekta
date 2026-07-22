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
use Laravel\Socialite\Exceptions\DriverMissingConfigurationException;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleAuthenticatedSessionController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
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
                $emailOwner = User::where('email', $email)->lockForUpdate()->first();

                if ($identityOwner) {
                    return ['user' => $identityOwner];
                }

                if ($emailOwner) {
                    return ['status' => 'link_required'];
                }

                return ['user' => $this->createGoogleUser($googleUser, $email, $googleId)];
            });
        } catch (UniqueConstraintViolationException $exception) {
            $result = DB::transaction(function () use ($googleId, $email) {
                $identityOwner = User::where('google_id', $googleId)->lockForUpdate()->first();
                $emailOwner = User::where('email', $email)->lockForUpdate()->first();

                if ($identityOwner && $emailOwner && $emailOwner->is($identityOwner)) {
                    return ['user' => $identityOwner];
                }

                return ['status' => 'conflict'];
            });
        } catch (QueryException $exception) {
            report($exception);

            return to_route('login')->with('status', 'Login Google sedang bermasalah. Silakan coba lagi.');
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
