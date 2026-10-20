<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent once, right after someone creates an account. */
class Welcome extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to BookKeepingLLC');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.welcome',
            with: [
                'name' => $this->user->name,
                'email' => $this->user->email,
                'companyName' => $this->user->company?->name,
                'loginUrl' => route('login'),
                'guideUrl' => route('help'),
            ],
        );
    }
}
