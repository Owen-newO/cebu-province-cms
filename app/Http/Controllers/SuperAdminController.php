<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\LguApplication;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Str;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class SuperAdminController extends Controller
{
    /**
     * Only the super-admin role may use any of the actions below.
     * Mirrors AdminController's own role gate.
     */
    private function denyUnlessSuperAdmin()
    {
        return strtolower(auth()->user()->role ?? '') !== 'super-admin'
            ? redirect()->route('Dashboard')
            : null;
    }

    public function index()
    {
        if ($redirect = $this->denyUnlessSuperAdmin()) {
            return $redirect;
        }

        $municipalities = config('municipalities', []);

        $approvedSlugs = LguApplication::where('status', 'approved')
            ->pluck('municipal_slug')
            ->unique()
            ->all();

        $municipalityOptions = collect($municipalities)->map(function ($name, $slug) use ($approvedSlugs) {
            return [
                'slug'              => $slug,
                'name'              => $name,
                'already_registered' => in_array($slug, $approvedSlugs, true),
            ];
        })->values();

        $invitations = Invitation::with('creator')
            ->latest()
            ->get()
            ->map(fn (Invitation $inv) => [
                'id'             => $inv->id,
                'municipal_slug' => $inv->municipal_slug,
                'municipal_name' => $municipalities[$inv->municipal_slug] ?? $inv->municipal_slug,
                'token'          => $inv->token,
                'link'           => url('/lgu-signup?token=' . $inv->token),
                'status'         => $inv->status,
                'expires_at'     => $inv->expires_at,
                'created_at'     => $inv->created_at,
            ]);

        $applications = LguApplication::with('reviewer')
            ->latest()
            ->get()
            ->map(fn (LguApplication $app) => [
                'id'                   => $app->id,
                'municipal_slug'       => $app->municipal_slug,
                'municipal_name'       => $municipalities[$app->municipal_slug] ?? $app->municipal_slug,
                'representative_name'  => $app->representative_name,
                'email'                => $app->email,
                'phone'                => $app->phone,
                'status'               => $app->status,
                'submitted_at'         => $app->created_at,
                'reviewed_at'          => $app->reviewed_at,
                'reviewed_by'          => $app->reviewer?->name,
            ]);

        return Inertia::render('SuperAdminDashboard', [
            'municipalities' => $municipalityOptions,
            'invitations'    => $invitations,
            'applications'   => $applications,
        ]);
    }

    public function storeInvitation(Request $request)
    {
        if ($redirect = $this->denyUnlessSuperAdmin()) {
            return $redirect;
        }

        $validated = $request->validate([
            'municipal_slug' => ['required', 'string'],
        ]);

        if (!array_key_exists($validated['municipal_slug'], config('municipalities', []))) {
            return back()->with('error', 'Unknown municipality.');
        }

        // bin2hex(random_bytes(6)) => 12-char hex token.
        do {
            $token = bin2hex(random_bytes(6));
        } while (Invitation::where('token', $token)->exists());

        Invitation::create([
            'token'          => $token,
            'municipal_slug' => $validated['municipal_slug'],
            'created_by'     => auth()->id(),
            'expires_at'     => now()->addDay(),
        ]);

        return back()->with('success', 'Invitation link generated.');
    }

    public function deactivateInvitation(Invitation $invitation)
    {
        if ($redirect = $this->denyUnlessSuperAdmin()) {
            return $redirect;
        }

        if ($invitation->isUsed()) {
            return back()->with('error', 'This link has already been used and cannot be deactivated.');
        }

        $invitation->update(['deactivated_at' => now()]);

        return back()->with('success', 'Invitation deactivated.');
    }

    public function approveApplication(LguApplication $application)
    {
        if ($redirect = $this->denyUnlessSuperAdmin()) {
            return $redirect;
        }

        if ($application->status !== 'pending') {
            return back()->with('error', 'This application has already been reviewed.');
        }

        $user = User::updateOrCreate(
            ['email' => $application->email],
            [
                'name'     => $application->representative_name,
                'password' => Hash::make(Str::random(40)),
                'role'     => $application->municipal_slug,
            ]
        );

        $application->update([
            'status'      => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ]);

        try {
            Password::sendResetLink(['email' => $user->email]);
        } catch (\Throwable $e) {
            Log::error('Failed to send LGU account password-setup email', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('success', "Approved. {$application->representative_name} can now set a password via the emailed link.");
    }

    public function declineApplication(LguApplication $application)
    {
        if ($redirect = $this->denyUnlessSuperAdmin()) {
            return $redirect;
        }

        if ($application->status !== 'pending') {
            return back()->with('error', 'This application has already been reviewed.');
        }

        $application->update([
            'status'      => 'declined',
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ]);

        return back()->with('success', 'Application declined.');
    }
}
