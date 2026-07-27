<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Input;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\ValueObject\Address;

/** Validated input for placing an order at checkout. */
final readonly class CheckoutInput
{
    public function __construct(
        public bool $pickup,
        public ?Address $shippingAddress,
        public ?DateTimeImmutable $scheduledFor,
        public ?string $note,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $pickup = (bool) ($data['pickup'] ?? false);

        return new self(
            pickup: $pickup,
            shippingAddress: isset($data['shipping_address']) && is_array($data['shipping_address'])
                ? Address::fromArray($data['shipping_address']) : null,
            scheduledFor: isset($data['scheduled_for']) && $data['scheduled_for'] !== ''
                ? new DateTimeImmutable((string) $data['scheduled_for']) : null,
            note: isset($data['note']) && $data['note'] !== '' ? (string) $data['note'] : null,
        );
    }
}
