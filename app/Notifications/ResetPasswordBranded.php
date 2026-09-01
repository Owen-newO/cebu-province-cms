<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Branded replacement for Laravel's default ResetPassword notification.
 * Used for LGU account setup (SuperAdmin approval) and any password reset —
 * fully custom MATA-branded HTML, no Laravel scaffolding.
 */
class ResetPasswordBranded extends Notification
{
    public function __construct(public string $token)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $email = $notifiable->getEmailForPasswordReset();

        // Same URL shape Fortify uses: /reset-password/{token}?email=...
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $email,
        ]);

        // If the account's role is a municipality slug, show its display name.
        $role          = strtolower(trim((string) ($notifiable->role ?? '')));
        $municipalName = config("municipalities.$role"); // null when not a municipality
        $roleLabel     = $municipalName ? 'LGU Editor' : 'Administrator';

        // Password reset link lifetime (minutes) from auth config.
        $broker        = config('auth.defaults.passwords', 'users');
        $expireMinutes = (int) config("auth.passwords.$broker.expire", 60);

        return (new MailMessage)
            ->subject('Set up your account — Cebu Province Virtual Tours')
            ->view('emails.reset-password', [
                'url'           => $url,
                'email'         => $email,
                'municipalName' => $municipalName,
                'roleLabel'     => $roleLabel,
                'expireMinutes' => $expireMinutes,
            ]);
    }
}
