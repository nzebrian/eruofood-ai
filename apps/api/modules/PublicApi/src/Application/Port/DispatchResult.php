<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Port;

/** The result of attempting an outbound webhook HTTP POST. */
final readonly class DispatchResult
{
    public function __construct(
        public bool $success,
        public ?int $statusCode,
        public ?string $error,
    ) {
    }
}
