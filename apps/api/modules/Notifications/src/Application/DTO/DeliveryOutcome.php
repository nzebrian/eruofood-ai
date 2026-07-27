<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\DTO;

/** The result of a channel sender attempting to deliver a notification. */
final readonly class DeliveryOutcome
{
    public function __construct(
        public bool $success,
        public ?string $detail = null,
    ) {
    }

    public static function ok(?string $detail = null): self
    {
        return new self(true, $detail);
    }

    public static function failed(string $detail): self
    {
        return new self(false, $detail);
    }
}
