<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Port;

use EruoFood\Payments\Application\DTO\GatewayResult;

/**
 * The half of a provider integration that reconciliation needs: the ability to
 * ask "what happened to the transfer I sent you?"
 *
 * ## Why this is separate from {@see PaymentGateway}
 *
 * Not every provider can answer it. A gateway with no transfer-status endpoint
 * — or one we have not integrated yet — must make that visible, and an
 * interface it simply does not implement says so at the type level. The
 * alternative, a method on `PaymentGateway` that returns `Unknown` for the
 * providers that cannot answer, is worse: "I asked and could not tell" and "I
 * never asked" become the same value, and reconciliation would close cases on
 * the strength of a question nobody put.
 *
 * So the settlement path tests for this interface. A provider that does not
 * implement it cannot have its transfers reconciled automatically, and the
 * discrepancy goes to a human instead of being silently resolved.
 */
interface PayoutGateway
{
    /**
     * Ask the provider for the current state of a transfer.
     *
     * The reference is the one we *sent*. On an `Unknown` outcome the provider
     * may never have created the transfer at all, so an implementation must
     * treat "not found" as a meaningful answer — see below — rather than an
     * error.
     *
     * Returns:
     * - `Succeeded` — the provider confirms the money left.
     * - `Processing` — accepted, still in flight.
     * - `Failed` — the provider confirms it did not happen. This is the answer
     *   that makes a retry safe, so an implementation must only return it when
     *   the provider genuinely said so, including an authoritative "no such
     *   transfer" for a reference we know we sent.
     * - `Unknown` — the status query itself failed. Never a resolution.
     *
     * Implementations must not throw on a transport failure; they return
     * `GatewayResult::unknown()`. A thrown exception would leave the caller
     * choosing a fallback, and the tempting fallback is the wrong one.
     */
    public function fetchTransferStatus(string $providerReference): GatewayResult;
}
