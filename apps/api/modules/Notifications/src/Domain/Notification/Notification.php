<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Notification;

use DateTimeImmutable;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Enum\NotificationClass;
use EruoFood\Notifications\Domain\Enum\NotificationStatus;
use EruoFood\Notifications\Domain\Enum\Priority;
use EruoFood\Notifications\Domain\Event\NotificationDispatched;
use EruoFood\Notifications\Domain\Exception\NotificationsInvalidState;
use EruoFood\Notifications\Domain\ValueObject\RenderedContent;
use EruoFood\Shared\Domain\AggregateRoot;

/**
 * A single notification to one user on one channel — the aggregate root over its
 * delivery lifecycle. It carries the rendered content, a guarded status
 * (pending → queued → sent → delivered, or failed → retry), an attempt counter
 * for the retry mechanism, an optional scheduled time, an immutable delivery
 * timeline, and a separate read flag for the in-app centre.
 */
final class Notification extends AggregateRoot
{
    /**
     * @param array<string, mixed> $data
     * @param list<array{status: string, at: string, detail: string|null}> $timeline
     */
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private readonly NotificationCategory $category,
        private readonly NotificationChannel $channel,
        private readonly string $templateKey,
        private readonly array $data,
        private RenderedContent $content,
        private readonly Priority $priority,
        private NotificationStatus $status,
        private int $attempts,
        private ?DateTimeImmutable $scheduledFor,
        private ?DateTimeImmutable $readAt,
        private array $timeline,
        private readonly DateTimeImmutable $createdAt,
        private readonly NotificationClass $class,
        private readonly ?string $correlationId,
        private ?string $providerMessageId,
        private bool $retryable = true,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(
        string $id,
        string $userId,
        NotificationCategory $category,
        NotificationChannel $channel,
        string $templateKey,
        array $data,
        RenderedContent $content,
        Priority $priority,
        ?DateTimeImmutable $scheduledFor,
        DateTimeImmutable $now,
        ?string $correlationId = null,
    ): self {
        return new self(
            $id,
            $userId,
            $category,
            $channel,
            $templateKey,
            $data,
            $content,
            $priority,
            NotificationStatus::Pending,
            0,
            $scheduledFor,
            null,
            [['status' => NotificationStatus::Pending->value, 'at' => $now->format(DATE_ATOM), 'detail' => null]],
            $now,
            // Derived from the category so a new category cannot arrive
            // unclassified, and stored so a consent audit sees what the message
            // *was* when it was sent.
            NotificationClass::forCategory($category),
            $correlationId,
            null,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{status: string, at: string, detail: string|null}> $timeline
     */
    public static function reconstitute(
        string $id,
        string $userId,
        NotificationCategory $category,
        NotificationChannel $channel,
        string $templateKey,
        array $data,
        RenderedContent $content,
        Priority $priority,
        NotificationStatus $status,
        int $attempts,
        ?DateTimeImmutable $scheduledFor,
        ?DateTimeImmutable $readAt,
        array $timeline,
        DateTimeImmutable $createdAt,
        ?NotificationClass $class = null,
        ?string $correlationId = null,
        ?string $providerMessageId = null,
        bool $retryable = true,
    ): self {
        return new self(
            $id,
            $userId,
            $category,
            $channel,
            $templateKey,
            $data,
            $content,
            $priority,
            $status,
            $attempts,
            $scheduledFor,
            $readAt,
            $timeline,
            $createdAt,
            $class ?? NotificationClass::forCategory($category),
            $correlationId,
            $providerMessageId,
            $retryable,
        );
    }

    public function queue(DateTimeImmutable $at): void
    {
        $this->transition(NotificationStatus::Queued, $at, null);
    }

    public function markSent(DateTimeImmutable $at, ?string $providerMessageId = null): void
    {
        $this->attempts++;
        $this->providerMessageId = $providerMessageId ?? $this->providerMessageId;
        $this->transition(NotificationStatus::Sent, $at, null);
        $this->recordThat(new NotificationDispatched($this->id, $this->userId, $this->channel->value, $this->category->value));
    }

    public function markDelivered(DateTimeImmutable $at): void
    {
        $this->transition(NotificationStatus::Delivered, $at, null);
    }

    public function markFailed(string $reason, DateTimeImmutable $at, bool $retryable = true): void
    {
        $this->attempts++;
        $this->retryable = $retryable;
        $this->transition(NotificationStatus::Failed, $at, $reason);
    }

    /**
     * Whether a failed notification may be retried under the given cap.
     *
     * A permanent failure — an address that does not exist, a recipient with no
     * email at all — is never retried. Re-attempting it until the cap is reached
     * burns quota and sender reputation on something that cannot succeed.
     */
    public function canRetry(int $maxAttempts): bool
    {
        return $this->status === NotificationStatus::Failed
            && $this->retryable
            && $this->attempts < $maxAttempts;
    }

    public function markRead(DateTimeImmutable $at): void
    {
        $this->readAt ??= $at;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return $this->scheduledFor === null || $this->scheduledFor <= $now;
    }

    public function isForUser(string $userId): bool
    {
        return $this->userId === $userId;
    }

    private function transition(NotificationStatus $next, DateTimeImmutable $at, ?string $detail): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new NotificationsInvalidState(sprintf('Cannot move a notification from "%s" to "%s".', $this->status->value, $next->value));
        }
        $this->status = $next;
        $this->timeline[] = ['status' => $next->value, 'at' => $at->format(DATE_ATOM), 'detail' => $detail];
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function category(): NotificationCategory
    {
        return $this->category;
    }

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function templateKey(): string
    {
        return $this->templateKey;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function content(): RenderedContent
    {
        return $this->content;
    }

    public function priority(): Priority
    {
        return $this->priority;
    }

    public function status(): NotificationStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function scheduledFor(): ?DateTimeImmutable
    {
        return $this->scheduledFor;
    }

    public function readAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    /** @return list<array{status: string, at: string, detail: string|null}> */
    public function timeline(): array
    {
        return $this->timeline;
    }

    public function class(): NotificationClass
    {
        return $this->class;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function providerMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
