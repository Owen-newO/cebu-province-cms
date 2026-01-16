<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Scene;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $municipal = strtolower($user->role);
        $barangays = config("barangays.$municipal", []);

        $scenesQuery = Scene::where('municipal', $municipal);

        $scenes = (clone $scenesQuery)
            ->where('is_published', 1)
            ->latest()
            ->get();

        $drafts = (clone $scenesQuery)
            ->where('is_published', 0)
            ->latest()
            ->get();

        return Inertia::render('Dashboard', [
            'municipal' => ucfirst($municipal),
    'scenes' => Scene::where('municipal', $municipal)->get(),
    'drafts' => Scene::where('municipal', $municipal)
                     ->where('status', 'draft')
                     ->get(),
]);
    }
}
