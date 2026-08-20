<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\DTO;

use EruoFood\Payments\Domain\Enum\GatewayOutcome;

/**
 * The outcome of a gateway operation (initialize / verify / refund / transfer).
 * `authorizationUrl` is the hosted checkout URL when the provider needs a
 * redirect; `status` is normalised to succeeded|processing|failed.
 *
 * ## The `success` flag is retained, and money-moving code must not read it
 *
 * `bool $success` cannot express "we do not know", and a bank transfer that
 * timed out is exactly that. Settlement and payout code reads {@see outcome()},
 * which returns a {@see GatewayOutcome} with an explicit `Unknown` case.
 *
 * The boolean stays because seven provider adapters and the whole M23 payment
 * path construct and read it, and rewriting them to fix a settlement bug would
 * put every existing payment flow at risk for no gain. New call sites use the
 * enum; `success` is derived from it when the enum is supplied, so the two can
 * never disagree.
 *
 * @phpstan-type Raw array<string, mixed>
 */
final readonly class GatewayResult
{
    /**
     * @param array<string, mixed> $raw
     * @param GatewayOutcome|null $outcome the provider's real answer. When null
     *                                     — every existing adapter — it is derived from $success and $status by
     *                                     {@see GatewayOutcome::fromLegacy()}, which resolves an unrecognised
     *                                     failure to `Unknown` rather than `Failed`.
     */
    public function __construct(
        public bool $success,
        public string $providerReference,
        public string $status, // succeeded|processing|failed
        public ?string $authorizationUrl = null,
        public ?string $message = null,
        public array $raw = [],
        private ?GatewayOutcome $outcome = null,
    ) {
    }

    /**
     * Build from an explicit outcome. The constructor of choice for anything
     * that moves money.
     *
     * @param array<string, mixed> $raw
     */
    public static function of(
        GatewayOutcome $outcome,
        string $providerReference,
        ?string $message = null,
        array $raw = [],
    ): self {
        return new self(
            success: $outcome->isConfirmed(),
            providerReference: $providerReference,
            status: $outcome->value,
            authorizationUrl: null,
            message: $message,
            raw: $raw,
            outcome: $outcome,
        );
    }

    /**
     * A transfer whose fate is genuinely unknown — a timeout, a reset
     * connection, a 5xx, an unparseable body.
     *
     * The provider reference may be the one we *sent*, not one the provider
     * confirmed; reconciliation searches on it rather than trusting it.
     */
    public static function unknown(string $providerReference, ?string $message = null): self
    {
        return self::of(GatewayOutcome::Unknown, $providerReference, $message);
    }

    /**
     * What the provider actually told us.
     *
     * Always prefer this to `$success` in code that moves money: the boolean
     * cannot distinguish a decline from silence, and those two call for
     * opposite actions.
     */
    public function outcome(): GatewayOutcome
    {
        return $this->outcome ?? GatewayOutcome::fromLegacy($this->success, $this->status);
    }
}
