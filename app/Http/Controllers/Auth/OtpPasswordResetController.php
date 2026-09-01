<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Self-service password reset via a 6-digit email code (OTP).
 * Drives the ResetPassword page: send code -> verify code -> set password.
 * Called over fetch/XHR, so every response is JSON.
 */
class OtpPasswordResetController extends Controller
{
    private const EXPIRY_MINUTES = 15;

    /** Step 1: email a fresh 6-digit code. */
    public function sendCode(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = $this->userByEmail($request->input('email'));
        if (!$user) {
            return response()->json(['message' => 'No account found for this email.'], 422);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($code), 'created_at' => now()]
        );

        Mail::send('emails.otp-code', [
            'code'          => $code,
            'expireMinutes' => self::EXPIRY_MINUTES,
        ], function ($m) use ($user) {
            $m->to($user->email)->subject('Your verification code — Cebu Province Virtual Maps');
        });

        return response()->json(['ok' => true]);
    }

    /** Step 2: check the code (does not consume it). */
    public function verifyCode(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code'  => ['required', 'string', 'size:6'],
        ]);

        if (!$this->codeIsValid($request->input('email'), $request->input('code'))) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Step 3: re-check the code and set the new password. */
    public function complete(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'code'     => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        if (!$this->codeIsValid($request->input('email'), $request->input('code'))) {
            return response()->json(['message' => 'Your code expired. Please request a new one.'], 422);
        }

        $user = $this->userByEmail($request->input('email'));
        if (!$user) {
            return response()->json(['message' => 'No account found for this email.'], 422);
        }

        $user->forceFill([
            'password'       => Hash::make($request->input('password')),
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        return response()->json(['ok' => true, 'redirect' => route('login')]);
    }

    private function userByEmail(?string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [strtolower(trim((string) $email))])->first();
    }

    private function codeIsValid(string $email, string $code): bool
    {
        $user = $this->userByEmail($email);
        if (!$user) {
            return false;
        }

        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        if (!$row) {
            return false;
        }

        if (Carbon::parse($row->created_at)->diffInMinutes(now()) > self::EXPIRY_MINUTES) {
            return false;
        }

        return Hash::check($code, $row->token);
    }
}
