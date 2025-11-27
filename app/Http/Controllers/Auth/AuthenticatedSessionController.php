<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class AuthenticatedSessionController extends Controller
{
    public function create()
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials)) {

            // 🔥 IMPORTANT: Regenerate session to avoid token mismatch
            $request->session()->regenerate();

            // 🔥 Regenerate CSRF token to avoid stale token
            Session::regenerateToken();

            // 🔥 Hard refresh to clear all stale Inertia data
            return redirect('/')->with('_force_reload', true);
        }

        return back()->withErrors([
            'email' => 'Invalid credentials provided.',
        ]);
    }

    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();

        // 🔥 Invalidate the session completely
        $request->session()->invalidate();

        // 🔥 Regenerate CSRF token
        $request->session()->regenerateToken();

        // 🔥 Hard refresh login page
        return redirect('/login')->with('_force_reload', true);
    }
}
