<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Channel;

use EruoFood\Notifications\Application\DTO\DeliveryOutcome;
use EruoFood\Notifications\Application\DTO\EmailMessage;
use EruoFood\Notifications\Application\Port\ChannelSender;
use EruoFood\Notifications\Application\Port\EmailProvider;
use EruoFood\Notifications\Application\Port\RecipientResolver;
use EruoFood\Notifications\Application\Service\EmailBodyRenderer;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Notification\Notification;
use EruoFood\Notifications\Domain\Preference\NotificationPreferenceRepository;

/**
 * The email channel: resolve who this is for, build the message, hand it to the
 * provider.
 *
 * This class owns *what an email is* — addressing, layout, unsubscribe headers —
 * and the {@see EmailProvider} beneath it owns only transmission. That split is
 * what lets the platform change ESP without touching notification logic, and
 * lets the whole engine be exercised in tests without sending anything.
 *
 * A recipient with no address is a **permanent** failure, not a retryable one.
 * Re-attempting delivery to an account that has no email, every retry cycle
 * until the cap, achieves nothing and hides real failures in the noise.
 */
final readonly class EmailChannelSender implements ChannelSender
{
    public function __construct(
        private EmailProvider $provider,
        private RecipientResolver $recipients,
        private EmailBodyRenderer $renderer,
        private NotificationPreferenceRepository $preferences,
        private ?string $unsubscribeBaseUrl = null,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function send(Notification $notification): DeliveryOutcome
    {
        $recipient = $this->recipients->resolve($notification->userId());

        if ($recipient === null || ! $recipient->hasEmail()) {
            return DeliveryOutcome::permanentlyFailed('Recipient has no email address.');
        }

        $body = $this->renderer->render($notification, $recipient);

        $result = $this->provider->send(new EmailMessage(
            toAddress: (string) $recipient->emailAddress,
            toName: $recipient->displayName,
            subject: $notification->content()->subject,
            htmlBody: $body->html,
            textBody: $body->text,
            headers: $this->headersFor($notification),
            correlationId: $notification->correlationId(),
        ));

        if ($result->success) {
            return DeliveryOutcome::ok($result->detail, $result->providerMessageId);
        }

        return $result->retryable
            ? DeliveryOutcome::failed((string) $result->detail)
            : DeliveryOutcome::permanentlyFailed((string) $result->detail);
    }

    /**
     * Unsubscribe headers, on marketing mail only.
     *
     * Attaching `List-Unsubscribe` to a verification or security email would
     * invite somebody to switch off the messages that tell them their account is
     * being tampered with — and would train mail clients to offer it.
     *
     * @return array<string, string>
     */
    private function headersFor(Notification $notification): array
    {
        if (! $notification->class()->carriesUnsubscribeHeaders() || $this->unsubscribeBaseUrl === null) {
            return [];
        }

        $token = $this->preferences->forUser($notification->userId())?->unsubscribeToken();
        if ($token === null) {
            return [];
        }

        $url = rtrim($this->unsubscribeBaseUrl, '/').'/'.$token;

        return [
            'List-Unsubscribe' => '<'.$url.'>',
            // Tells the client the link may be called directly, so a one-click
            // unsubscribe does not require the user to land on a page and hunt.
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }
}
