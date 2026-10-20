<?php

namespace App\Listeners;

use App\Mail\Welcome;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Emails the welcome message after sign-up. A mail problem never blocks the sign-up itself. */
class SendWelcomeEmail
{
    public function handle(Registered $event): void
    {
        $user = $event->user;
        if (! $user instanceof User || ! $user->email) {
            return;
        }

        try {
            Mail::to($user->email)->send(new Welcome($user));
        } catch (Throwable $e) {
            Log::error('Welcome email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
