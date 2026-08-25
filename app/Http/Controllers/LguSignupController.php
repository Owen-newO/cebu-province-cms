<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\LguApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LguSignupController extends Controller
{
    public function show(Request $request)
    {
        $token = (string) $request->query('token', '');
        $invitation = Invitation::where('token', $token)->first();

        return Inertia::render('LguSignup', [
            'token'           => $token,
            'valid'           => $invitation && $invitation->isActive(),
            'reason'          => $this->invalidReason($invitation),
            'municipal_name'  => $invitation
                ? (config('municipalities')[$invitation->municipal_slug] ?? $invitation->municipal_slug)
                : null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'token'                => ['required', 'string'],
            'representative_name'  => ['required', 'string', 'max:255'],
            'email'                => ['required', 'email', 'max:255'],
            'phone'                => ['required', 'string', 'max:30'],
        ]);

        return DB::transaction(function () use ($validated) {
            // lockForUpdate to close the race between two people submitting
            // the same one-time link at the same moment.
            $invitation = Invitation::where('token', $validated['token'])
                ->lockForUpdate()
                ->first();

            if (!$invitation || !$invitation->isActive()) {
                return back()->with('error', 'This invitation link is no longer valid.');
            }

            LguApplication::create([
                'invitation_id'        => $invitation->id,
                'municipal_slug'       => $invitation->municipal_slug,
                'representative_name'  => $validated['representative_name'],
                'email'                => $validated['email'],
                'phone'                => $validated['phone'],
            ]);

            $invitation->update(['used_at' => now()]);

            return back()->with('success', 'Application submitted. The province will review it shortly.');
        });
    }

    private function invalidReason(?Invitation $invitation): ?string
    {
        if (!$invitation) return 'invalid';
        if ($invitation->isUsed()) return 'used';
        if ($invitation->isDeactivated()) return 'deactivated';
        if ($invitation->isExpired()) return 'expired';

        return null;
    }
}
