<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * Nobody could take this delivery.
 *
 * An honest outcome, not a bug. The alternative — relaxing eligibility until
 * somebody qualifies — puts an unverified rider, or one with expired
 * insurance, on the road to satisfy a queue.
 */
final class NoEligibleRiders extends DomainException
{
    /** @param array<string, int> $breakdown reason => count */
    public static function withBreakdown(array $breakdown = []): self
    {
        return new self('No eligible rider is available for this delivery right now.');
    }

    public function errorCode(): string
    {
        return 'DISPATCH_NO_ELIGIBLE_RIDERS';
    }
}
