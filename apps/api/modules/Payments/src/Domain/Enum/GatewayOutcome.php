<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/**
 * What a provider actually told us — including "nothing".
 *
 * ## The gap this closes
 *
 * {@see \EruoFood\Payments\Application\DTO\GatewayResult} carried
 * `public bool $success`. Two values, three realities: the transfer succeeded,
 * the transfer was declined, and *we do not know*. The third collapsed into the
 * second, so a socket timeout on a bank transfer read as a decline — and a
 * declined transfer is safe to retry.
 *
 * A retried transfer that had in fact succeeded pays a merchant twice. That is
 * the specific accident this enum exists to make impossible: `Unknown` is not
 * `Failed`, cannot be treated as `Failed`, and has no path to a retry that does
 * not pass through reconciliation first.
 *
 * ## Why `Processing` is separate from `Unknown`
 *
 * They look alike and are not. `Processing` is the provider telling us it has
 * the instruction and is working on it — an answer, and a good one. `Unknown`
 * is the absence of an answer. We may poll a `Processing` transfer confidently;
 * an `Unknown` one has to be *searched for*, because it may not exist at all.
 */
enum GatewayOutcome: string implements ServerAuthoritative
{
    /** The provider confirmed the operation completed. */
    case Succeeded = 'succeeded';

    /** The provider accepted it and has not finished. A real answer. */
    case Processing = 'processing';

    /** The provider refused it. Nothing moved, and nothing will. */
    case Failed = 'failed';

    /**
     * We do not know. Timeout, connection reset, 5xx, unparseable body.
     *
     * The operation may have completed, may have been rejected, may never have
     * reached the provider. All three are live until reconciliation says
     * otherwise.
     */
    case Unknown = 'unknown';

    /**
     * Build from a transport-level failure.
     *
     * Deliberately the only constructor a `catch` block should reach for: an
     * adapter that turns an exception into `Failed` has invented information it
     * does not have.
     */
    public static function fromTransportFailure(): self
    {
        return self::Unknown;
    }

    /**
     * Whether money definitely moved.
     *
     * Only `Succeeded`. `Processing` is excluded because the provider may still
     * reject it, and posting a payout ledger entry for a transfer that later
     * fails leaves the books claiming money left that never did.
     */
    public function isConfirmed(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * Whether the caller may safely attempt the same money movement again.
     *
     * `Failed` alone. This is the method the settlement path calls before
     * creating a second payout attempt, and the reason `Unknown` is not simply
     * `! $success`.
     */
    public function isSafelyRetryable(): bool
    {
        return $this === self::Failed;
    }

    /** Whether a human or a reconciler must establish what happened. */
    public function requiresReconciliation(): bool
    {
        return $this === self::Unknown;
    }

    public function serverPhase(): ServerPhase
    {
        return match ($this) {
            self::Succeeded => ServerPhase::Confirmed,
            // Both map to Processing, so `ServerPhase::isSafelyRetryable()` —
            // which already refuses Processing, and is already tested — refuses
            // them without a second rule to keep in step.
            self::Processing, self::Unknown => ServerPhase::Processing,
            self::Failed => ServerPhase::Failed,
        };
    }

    /**
     * Derive an outcome from the legacy `success` + `status` pair.
     *
     * Used only to keep the seven existing provider adapters working unchanged.
     * It is intentionally conservative in one direction and not the other: an
     * adapter that says `success: false` might mean "declined" or might mean
     * "the HTTP call blew up and I defaulted", so a *known* failure status is
     * required before this returns `Failed`. Anything else with `success:false`
     * becomes `Unknown`, which is the safe side of the mistake.
     */
    public static function fromLegacy(bool $success, string $status): self
    {
        $normalised = strtolower(trim($status));

        if ($success) {
            return $normalised === 'processing' || $normalised === 'pending'
                ? self::Processing
                : self::Succeeded;
        }

        return match ($normalised) {
            'failed', 'declined', 'rejected', 'cancelled', 'canceled' => self::Failed,
            'processing', 'pending' => self::Processing,
            default => self::Unknown,
        };
    }
}
