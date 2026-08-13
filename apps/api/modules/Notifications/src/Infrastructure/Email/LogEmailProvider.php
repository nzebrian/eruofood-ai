<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Email;

use EruoFood\Notifications\Application\DTO\EmailDispatchResult;
use EruoFood\Notifications\Application\DTO\EmailMessage;
use EruoFood\Notifications\Application\Port\EmailProvider;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * The offline-safe default: records that a send was requested and transmits
 * nothing.
 *
 * Used in local development and tests, where a real send would be at best noise
 * and at worst a real email to a real person seeded into a fixture.
 *
 * Logs the subject and a hashed address rather than the address itself. A
 * notification log is read by more people, retained longer and shipped further
 * than the identity store it came from, and it is not the place to accumulate a
 * second copy of everybody's email address.
 */
final readonly class LogEmailProvider implements EmailProvider
{
    public function __construct(private LoggerInterface $log)
    {
    }

    public function name(): string
    {
        return 'log';
    }

    public function send(EmailMessage $message): EmailDispatchResult
    {
        $messageId = 'log-'.Str::uuid()->toString();

        $this->log->info('notifications.email.dispatched', [
            'provider' => $this->name(),
            'provider_message_id' => $messageId,
            'correlation_id' => $message->correlationId,
            'subject' => $message->subject,
            // Enough to correlate repeated sends to one person without storing
            // the address.
            'recipient_sha256' => hash('sha256', $message->toAddress),
        ]);

        return EmailDispatchResult::sent($messageId, 'Logged; no transport configured.');
    }
}
