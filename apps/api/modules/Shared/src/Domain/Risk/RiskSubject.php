<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Risk;

/**
 * Who or what is being assessed.
 *
 * Every field is optional because the callers differ: account creation has a
 * device and no user yet, checkout has a user and an order, dispatch has a
 * rider. A detector uses what it is given.
 *
 * ## No raw personal data
 *
 * Identifiers only — no names, no addresses, no card numbers, no documents.
 * Risk signals flow to analysis systems and eventually to logs, and requirement
 * 14 forbids sensitive data reaching either. A detector that needs more than an
 * id can join to it under its own authorisation.
 */
final readonly class RiskSubject
{
    private function __construct(
        public ?string $userId,
        public ?string $deviceId,
        public ?string $riderId,
        public ?string $merchantId,
        public ?string $orderId,
        public ?string $ipAddress,
    ) {
    }

    public static function of(
        ?string $userId = null,
        ?string $deviceId = null,
        ?string $riderId = null,
        ?string $merchantId = null,
        ?string $orderId = null,
        ?string $ipAddress = null,
    ): self {
        return new self($userId, $deviceId, $riderId, $merchantId, $orderId, $ipAddress);
    }

    /** Whether there is anything here to assess at all. */
    public function isIdentifiable(): bool
    {
        return $this->userId !== null
            || $this->deviceId !== null
            || $this->riderId !== null
            || $this->merchantId !== null;
    }
}
