<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\SceneController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;

use App\Models\Scene;
// Route::get('/', function () {
//     return Inertia::render('Dashboard', [
//         'canLogin' => Route::has('login'),
//         'canRegister' => Route::has('register'),
//         'laravelVersion' => Application::VERSION,
//         'phpVersion' => PHP_VERSION,
//     ]);
// })->middleware(['auth', 'verified']);

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    // your current home dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('Dashboard');
    Route::post('/dashboard', [SceneController::class, 'index'])->name('dashboard.post');
    Route::post('/scenes', [SceneController::class, 'store'])->name('scenes.store');
    Route::delete('/scenes/{id}', [SceneController::class, 'destroy'])->name('scenes.destroy');
    Route::get('/api/scenes', fn() => response()->json(App\Models\Scene::latest()->get()));
});

Route::post('/scenes', [SceneController::class, 'store'])->name('scenes.store');

Route::match(['post', 'put'], '/scenes/{id}/update', [SceneController::class, 'update'])
    ->name('scenes.update');

Route::delete('/scenes/{id}', [SceneController::class, 'destroy'])->name('scenes.destroy');

// Auth routes
Route::get('/login', [AuthenticatedSessionController::class, 'create'])
    ->middleware('guest')
    ->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// ✅ Barangay Dashboards
// Route::get('/dashboard/Xq1f4Psl3Smbn', fn() => Inertia::render('Dashboards/Dashboardsamboan'))->name('Dashboard.samboan');
// Route::get('/dashboard/Q9zG8Htl0Oslb', fn() => Inertia::render('Dashboards/Dashboardoslob'))->name('Dashboard.oslob');
// Route::get('/dashboard/W7aP2Rty5Tubr', fn() => Inertia::render('Dashboards/Dashboardtuburan'))->name('Dashboard.tuburan');
