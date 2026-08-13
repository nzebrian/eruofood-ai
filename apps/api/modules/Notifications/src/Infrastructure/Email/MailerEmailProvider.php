<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Email;

use EruoFood\Notifications\Application\DTO\EmailDispatchResult;
use EruoFood\Notifications\Application\DTO\EmailMessage;
use EruoFood\Notifications\Application\Port\EmailProvider;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Transmits through Laravel's configured mailer, whatever that is bound to —
 * SMTP, SES, Postmark, Resend. The credentials live in the mail configuration
 * and its environment; none of them appear here or in any template.
 *
 * The provider's own message id is read back off the sent message so support can
 * trace a specific email at the ESP.
 *
 * Failures are classified rather than rethrown. A transport exception is
 * transient and worth retrying; a malformed address is not, and retrying it
 * would burn quota and sender reputation on something that can never succeed.
 */
final readonly class MailerEmailProvider implements EmailProvider
{
    public function __construct(
        private Mailer $mailer,
        private LoggerInterface $log,
        private ?string $fromAddress = null,
        private ?string $fromName = null,
    ) {
    }

    public function name(): string
    {
        return 'mailer';
    }

    public function send(EmailMessage $message): EmailDispatchResult
    {
        $providerMessageId = null;

        try {
            $this->mailer->html($message->htmlBody, function (Message $mail) use ($message, &$providerMessageId): void {
                $mail->to($message->toAddress, $message->toName)
                    ->subject($message->subject)
                    ->text($message->textBody);

                if ($this->fromAddress !== null && $this->fromAddress !== '') {
                    $mail->from($this->fromAddress, $this->fromName);
                }

                foreach ($message->headers as $name => $value) {
                    $mail->getHeaders()->addTextHeader($name, $value);
                }

                if ($message->correlationId !== null) {
                    $mail->getHeaders()->addTextHeader('X-Correlation-Id', $message->correlationId);
                }

                $providerMessageId = trim($mail->getSymfonyMessage()->generateMessageId());
            });
        } catch (TransportExceptionInterface $e) {
            // The provider was unreachable or refused the message temporarily.
            return EmailDispatchResult::transientFailure($this->safeReason($e));
        } catch (Throwable $e) {
            // A malformed address or a rendering fault: retrying cannot help.
            return EmailDispatchResult::permanentFailure($this->safeReason($e));
        }

        return EmailDispatchResult::sent($providerMessageId);
    }

    /**
     * A failure reason safe to store and log.
     *
     * Provider exception messages routinely quote the offending recipient
     * address, and sometimes the credentials used to authenticate. Both would
     * end up in the delivery record, which is exactly where neither belongs.
     */
    private function safeReason(Throwable $e): string
    {
        $this->log->warning('notifications.email.failed', [
            'provider' => $this->name(),
            'exception' => $e::class,
        ]);

        return $e::class;
    }
}
