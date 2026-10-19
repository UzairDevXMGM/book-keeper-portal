<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;

/** Sign-ins, failed sign-ins, sign-ups and password resets go to the audit log. */
class RecordAuthEvents
{
    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            Audit::log('auth.login', $event->user, user: $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        // Only the email that was typed is kept, never the password
        Audit::log('auth.failed', $event->user instanceof User ? $event->user : null, ['email' => mb_substr((string) ($event->credentials['email'] ?? ''), 0, 120)]);
    }

    public function handleRegistered(Registered $event): void
    {
        if ($event->user instanceof User) {
            Audit::log('auth.registered', $event->user->company ?? $event->user, ['email' => $event->user->email], user: $event->user);
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            Audit::log('auth.password_reset', $event->user, user: $event->user);
        }
    }
}
