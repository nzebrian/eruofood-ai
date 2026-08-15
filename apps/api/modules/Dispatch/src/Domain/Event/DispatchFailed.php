<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * The search ended without a rider, and operations owns it now.
 *
 * Carries the reason and the dominant rejection, because an alert that says
 * only "dispatch failed" sends somebody to read a database at 8pm on a Friday.
 * "Eleven riders nearby, nine stale locations" is a next action.
 */
final readonly class DispatchFailed implements DomainEvent
{
    public function __construct(
        public string $requestId,
        public string $deliveryId,
        public string $reason,
        public ?string $dominantRejection,
        public int $attemptCount,
        private DateTimeImmutable $at,
    ) {
    }

    public function eventName(): string
    {
        return 'dispatch.failed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
