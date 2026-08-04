<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A share of a payment routed to a payee (split payments / marketplace payouts).
 * The sum of splits is validated against the payment amount by the aggregate.
 */
final readonly class PaymentSplit
{
    public function __construct(
        public string $payeeType, // vendor|driver|platform
        public string $payeeId,
        public Money $amount,
    ) {
        if ($amount->minorUnits < 0) {
            throw new InvalidArgumentException('Split amount cannot be negative.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $currency): self
    {
        return new self(
            (string) $data['payee_type'],
            (string) $data['payee_id'],
            new Money((int) $data['amount_minor'], $currency),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'payee_type' => $this->payeeType,
            'payee_id' => $this->payeeId,
            'amount_minor' => $this->amount->minorUnits,
        ];
    }
}
