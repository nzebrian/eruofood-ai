<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Flag;

/**
 * Who a flag is being evaluated for.
 *
 * Controlled rollout means "on in Lagos but not Abuja", "on for these three
 * merchants", "on for one percent of customers". That needs the *subject* of
 * the decision, not just the flag name.
 *
 * ## Everything is optional, and unknown is not a match
 *
 * A background job evaluating a flag has no merchant and no country. Rather
 * than force callers to invent values, every field is nullable and a rule that
 * targets a dimension the caller did not supply simply does not match. The
 * effect is that missing context can never *accidentally* enable something —
 * it can only fail to enable it, which is the safe direction.
 *
 * ## Not client-supplied
 *
 * These values come from the server's own view of the request — the
 * authenticated user, the merchant on the order, the region resolved from the
 * delivery address. Taking them from the request body would let a caller
 * choose which rollout bucket they are in, which turns a rollout control into
 * an authorization bypass for anything gated behind a flag.
 */
final readonly class FlagTarget
{
    private function __construct(
        public ?string $userId,
        public ?string $merchantId,
        public ?string $countryCode,
        public ?string $regionCode,
    ) {
    }

    public static function of(
        ?string $userId = null,
        ?string $merchantId = null,
        ?string $countryCode = null,
        ?string $regionCode = null,
    ): self {
        return new self(
            self::clean($userId),
            self::clean($merchantId),
            $countryCode === null ? null : strtoupper(trim($countryCode)),
            self::clean($regionCode),
        );
    }

    /** No subject at all — a scheduled sweep, a console command, a migration. */
    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    /**
     * A stable number in [0, 100) for percentage rollouts.
     *
     * Derived by hashing the flag key together with the most specific
     * identifier available. Including the key means a user in the first 5% of
     * one rollout is not automatically in the first 5% of every other one —
     * otherwise the same unlucky cohort receives every experimental feature.
     *
     * Stable across processes and deploys, because it is a pure function of
     * values rather than anything random or time-based: a customer does not see
     * a feature appear and disappear between requests.
     */
    public function bucketFor(string $flagKey): ?int
    {
        $identity = $this->userId ?? $this->merchantId;

        if ($identity === null) {
            return null;
        }

        return (int) (hexdec(substr(hash('sha256', $flagKey.':'.$identity), 0, 8)) % 100);
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
