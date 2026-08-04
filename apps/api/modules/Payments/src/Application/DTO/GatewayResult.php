<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\DTO;

/**
 * The outcome of a gateway operation (initialize / verify / refund / transfer).
 * `authorizationUrl` is the hosted checkout URL when the provider needs a
 * redirect; `status` is normalised to succeeded|processing|failed.
 *
 * @phpstan-type Raw array<string, mixed>
 */
final readonly class GatewayResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public bool $success,
        public string $providerReference,
        public string $status, // succeeded|processing|failed
        public ?string $authorizationUrl = null,
        public ?string $message = null,
        public array $raw = [],
    ) {
    }
}
