<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Send the user to Google's login/consent screen.
     */
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Google sends the browser back here with a verified profile.
     *
     * This app does NOT let a Google login auto-create an account: every
     * account here carries a role (admin / super-admin / a municipality
     * slug) that controls which dashboard it can reach, so a stranger's
     * Google account must never self-provision access. The Google email
     * only has to match an EXISTING user row (seeded, or created by the
     * super-admin's LGU-application approval flow).
     */
    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            Log::warning('Google OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect()->route('login')->with(
                'google_error',
                'Google sign-in was cancelled or failed. Please try again.'
            );
        }

        $user = User::where('email', $googleUser->getEmail())->first();

        if (!$user) {
            return redirect()->route('login')->with(
                'google_error',
                "No account found for {$googleUser->getEmail()}. Ask your administrator to add it first."
            );
        }

        Auth::login($user, remember: true);
        Session::regenerate();

        return redirect('/')->with('_force_reload', true);
    }
}
