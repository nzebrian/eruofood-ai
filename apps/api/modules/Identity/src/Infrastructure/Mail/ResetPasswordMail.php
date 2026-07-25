<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Queued email carrying the password-reset link. */
final class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public string $resetUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your EruoFood AI password');
    }

    public function content(): Content
    {
        return new Content(view: 'identity::emails.reset-password');
    }
}
