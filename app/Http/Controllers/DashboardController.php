<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Scene;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        // Normalize municipal
        $municipal = strtolower($user->role);

        // Barangays config
        $barangays = config("barangays.$municipal", []);

        // Fetch ALL scenes for this municipal
        $scenes = Scene::where('municipal', $municipal)
            ->latest()
            ->get();

        // Split published vs drafts (no cloning queries needed)
        $published = $scenes->where('is_published', 1)->values();
        $drafts    = $scenes->where('is_published', 0)->values();

        return Inertia::render('Dashboard', [
            'scenes'     => $published,
            'drafts'     => $drafts,
            'municipal'  => ucfirst($municipal),
            'barangays'  => $barangays,
        ]);
    }
}
