<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Queued email carrying the address-verification link. */
final class VerifyEmailMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $name,
        public string $verificationUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your EruoFood AI email');
    }

    public function content(): Content
    {
        return new Content(view: 'identity::emails.verify-email');
    }
}
