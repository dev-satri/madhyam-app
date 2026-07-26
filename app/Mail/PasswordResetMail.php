<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $resetUrl,
        public int $expiresInMinutes = 60,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your ' . config('app.name', 'Madhyam') . ' password',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.password-reset',
        );
    }
}
