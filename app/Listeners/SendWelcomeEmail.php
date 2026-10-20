<?php

namespace App\Listeners;

use App\Mail\Welcome;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Emails the welcome message after sign-up. It is sent after the page has answered (defer), so a slow or broken
 * mail server never slows down or blocks the sign-up itself.
 */
class SendWelcomeEmail
{
    public function handle(Registered $event): void
    {
        $user = $event->user;
        if (! $user instanceof User || ! $user->email) {
            return;
        }

        defer(function () use ($user) {
            try {
                Mail::to($user->email)->send(new Welcome($user));
            } catch (Throwable $e) {
                Log::error('Welcome email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        });
    }
}
