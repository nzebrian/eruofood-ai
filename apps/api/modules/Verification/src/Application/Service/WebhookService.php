<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Service;

use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Verification\Application\DTO\WebhookHeaders;
use EruoFood\Verification\Application\Port\VerificationProviderRegistry;
use EruoFood\Verification\Domain\Attempt\AttemptRepository;
use EruoFood\Verification\Domain\Enum\ActorType;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Exception\WebhookRejected;
use EruoFood\Verification\Domain\Webhook\WebhookEventRepository;

/**
 * Processes inbound provider callbacks exactly once.
 *
 * The ordering here is the security property, and it is deliberate:
 *
 * 1. **Authenticate before anything else.** The adapter verifies the signature
 *    and the replay window and only then hands back a parsed notification. A
 *    payload that fails is never seen by any code that could act on it. This is
 *    what makes "never trust the client" concrete — a caller cannot assert a
 *    verification succeeded, because an unsigned body cannot get past this line.
 *
 * 2. **Resolve the reference to a real attempt.** A signature proves the message
 *    came from the provider; it does not prove the session belongs to a case we
 *    opened. An unknown reference is rejected rather than creating anything.
 *
 * 3. **Claim, then apply, in one transaction.** The claim is an insert against a
 *    unique index, so simultaneous redeliveries are arbitrated by the database
 *    rather than by a check-then-act window. Because claim and work share a
 *    transaction, a failure rolls the claim back and the provider's retry is
 *    honoured instead of being dismissed as a duplicate.
 *
 * This is M23's exactly-once webhook pattern applied to identity rather than
 * money — the same reasoning, reusing the same primitives.
 */
final readonly class WebhookService
{
    public function __construct(
        private VerificationProviderRegistry $providers,
        private WebhookEventRepository $seen,
        private AttemptRepository $attempts,
        private VerificationService $verification,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * @return bool whether this delivery was newly applied (false = duplicate)
     *
     * @throws WebhookRejected when the payload is not authentic, is replayed, or
     *                         references an unknown session
     */
    public function handle(string $providerName, string $rawBody, WebhookHeaders $headers): bool
    {
        $provider = ProviderName::tryFrom($providerName) ?? throw WebhookRejected::badSignature();

        // Step 1 — authenticate. Raises before returning anything if it fails.
        $notification = $this->providers->for($provider)->parseWebhook($rawBody, $headers);

        // Step 2 — the reference must map to an attempt we started.
        $attempt = $this->attempts->findByProviderReference($notification->providerReference);
        if ($attempt === null || $attempt->provider() !== $provider) {
            throw WebhookRejected::unknownReference();
        }

        // Step 3 — claim and apply together.
        [$applied, $case] = $this->transactions->atomic(function () use ($provider, $notification, $attempt): array {
            if (! $this->seen->claim($provider, $notification->providerEventId, $notification->signatureScheme)) {
                return [false, null]; // a redelivery of an event already handled
            }

            $case = $this->verification->applyDecision(
                $attempt->caseId(),
                $notification->decision,
                ActorType::Provider,
                $provider->value,
            );

            return [true, $case];
        });

        // Side effects only once the transaction has committed: a subscriber
        // must never react to a verification a rollback is about to undo.
        if ($case !== null) {
            $this->verification->announce($case);
        }

        return $applied;
    }
}
