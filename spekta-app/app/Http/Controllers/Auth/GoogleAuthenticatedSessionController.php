<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WorkspaceProvisioner;
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
        } catch (InvalidStateException|DriverMissingConfigurationException $exception) {
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

        if (! (bool) data_get($googleUser->user, 'verified_email')) {
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
                'password' => Hash::make(Str::random(64)),
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $workspace = app(WorkspaceProvisioner::class)->provision($user, "Workspace {$user->name}");
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();

            return $user;
        });

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
