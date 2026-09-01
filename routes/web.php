<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\SceneController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\LguSignupController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleAuthController;

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
    // MATA admin dashboard (municipality selector); role=admin only
    Route::get('/admin', [AdminController::class, 'index'])->name('admin');

    // Super-admin: generates one-time LGU invitation links and reviews
    // the applications submitted through them. role=super-admin only
    // (enforced inside the controller).
    Route::get('/superadmin', [SuperAdminController::class, 'index'])->name('superadmin');
    Route::post('/superadmin/invitations', [SuperAdminController::class, 'storeInvitation'])
        ->name('superadmin.invitations.store');
    Route::post('/superadmin/invitations/{invitation}/deactivate', [SuperAdminController::class, 'deactivateInvitation'])
        ->name('superadmin.invitations.deactivate');
    Route::post('/superadmin/applications/{application}/approve', [SuperAdminController::class, 'approveApplication'])
        ->name('superadmin.applications.approve');
    Route::post('/superadmin/applications/{application}/decline', [SuperAdminController::class, 'declineApplication'])
        ->name('superadmin.applications.decline');
    Route::post('/dashboard', [SceneController::class, 'index'])->name('dashboard.post');
    Route::post('/scenes', [SceneController::class, 'store'])->name('scenes.store');
    Route::delete('/scenes/{id}', [SceneController::class, 'destroy'])->name('scenes.destroy');
    Route::post('/scenes/fix-layer-names', [SceneController::class, 'fixChildLayerNames'])
        ->name('scenes.fixLayerNames');
    Route::post('/scenes/hlookat-180', [SceneController::class, 'setSceneViewHlookat180'])
        ->name('scenes.hlookat180');
    Route::post('/scenes/hlookat-0', [SceneController::class, 'setSceneViewHlookat0'])
        ->name('scenes.hlookat0');
    Route::post('/scenes/inject-cebu', [SceneController::class, 'injectAllThumbsToCebu'])
        ->name('scenes.injectCebu');
    Route::post('/scenes/fix-topni', [SceneController::class, 'fixTopni'])
        ->name('scenes.fixTopni');
    Route::post('/scenes/lay-prefix', [SceneController::class, 'addLayPrefixToThumbs'])
        ->name('scenes.layPrefix');
    Route::post('/scenes/fix-modal-htgt', [SceneController::class, 'fixModalHtgt'])
        ->name('scenes.fixModalHtgt');
    Route::get('/api/scenes', fn() => response()->json(App\Models\Scene::latest()->get()));

    // Scenes for one municipality. props.municipal is ucfirst'd (e.g. "Pilar")
    // while the DB stores it lowercased, so match case-insensitively.
    Route::get('/api/scenes/{municipal}', function (string $municipal) {
        return response()->json(
            App\Models\Scene::whereRaw('LOWER(municipal) = ?', [strtolower($municipal)])
                ->latest()
                ->get()
        );
    });
});

Route::post('/scenes', [SceneController::class, 'store'])->name('scenes.store');

Route::match(['post', 'put'], '/scenes/{id}/update', [SceneController::class, 'update'])
    ->name('scenes.update');

Route::post('/scenes/{scene}/publish', [SceneController::class, 'publish'])
    ->name('scenes.publish');

Route::delete('/scenes/{id}', [SceneController::class, 'destroy'])->name('scenes.destroy');

// Public LGU onboarding — reached only via a super-admin-generated,
// one-time, 24h invitation link. No auth required (applicant has no
// account yet).
Route::get('/lgu-signup', [LguSignupController::class, 'show'])->name('lgu.signup');
Route::post('/lgu-signup', [LguSignupController::class, 'store'])->name('lgu.signup.store');

// Auth routes
Route::get('/login', [AuthenticatedSessionController::class, 'create'])
    ->middleware('guest')
    ->name('login');

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])
    ->middleware('guest')
    ->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
    ->middleware('guest')
    ->name('auth.google.callback');
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Self-service password reset via 6-digit email code (OTP)
Route::middleware('guest')->group(function () {
    // Tokenless entry to the OTP reset page (from the login "Forgot password?" link)
    Route::get('/reset-password', fn () => Inertia::render('Auth/ResetPassword'))
        ->name('password.otp');
    Route::post('/reset/code', [App\Http\Controllers\Auth\OtpPasswordResetController::class, 'sendCode'])
        ->name('otp.code');
    Route::post('/reset/verify', [App\Http\Controllers\Auth\OtpPasswordResetController::class, 'verifyCode'])
        ->name('otp.verify');
    Route::post('/reset/complete', [App\Http\Controllers\Auth\OtpPasswordResetController::class, 'complete'])
        ->name('otp.complete');
});

// ✅ Barangay Dashboards
// Route::get('/dashboard/Xq1f4Psl3Smbn', fn() => Inertia::render('Dashboards/Dashboardsamboan'))->name('Dashboard.samboan');
// Route::get('/dashboard/Q9zG8Htl0Oslb', fn() => Inertia::render('Dashboards/Dashboardoslob'))->name('Dashboard.oslob');
// Route::get('/dashboard/W7aP2Rty5Tubr', fn() => Inertia::render('Dashboards/Dashboardtuburan'))->name('Dashboard.tuburan');
