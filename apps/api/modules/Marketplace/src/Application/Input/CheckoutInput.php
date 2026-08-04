<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Input;

use DateTimeImmutable;
use EruoFood\Marketplace\Domain\Enum\FulfilmentType;
use EruoFood\Marketplace\Domain\ValueObject\Address;

/** Validated input for checking out the cart into an order. */
final readonly class CheckoutInput
{
    public function __construct(
        public FulfilmentType $fulfilment,
        public ?Address $deliveryAddress,
        public ?DateTimeImmutable $scheduledFor,
        public ?string $note,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            fulfilment: FulfilmentType::from((string) ($data['fulfilment'] ?? 'delivery')),
            deliveryAddress: isset($data['delivery_address']) && is_array($data['delivery_address'])
                ? Address::fromArray($data['delivery_address'])
                : null,
            scheduledFor: isset($data['scheduled_for']) && $data['scheduled_for'] !== ''
                ? new DateTimeImmutable((string) $data['scheduled_for'])
                : null,
            note: isset($data['note']) ? (string) $data['note'] : null,
        );
    }
}
