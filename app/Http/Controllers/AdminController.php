<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class AdminController extends Controller
{
    public function index()
    {
        // Only the admin account may see this page.
        if (strtolower(auth()->user()->role ?? '') !== 'admin') {
            return redirect()->route('Dashboard');
        }

        // slug => Display name (44 municipalities).
        $municipalities = config('municipalities', []);

        // slug => [barangay names]. Only some municipalities have barangay data
        // configured; the rest default to an empty list.
        $barangays = [];
        foreach (array_keys($municipalities) as $slug) {
            $barangays[$slug] = config("barangays.$slug", []);
        }

        return Inertia::render('AdminDashboard', [
            'municipalities' => $municipalities,
            'barangays'      => $barangays,
        ]);
    }
}
