<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Service;

use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Verification\Application\Port\VerificationProviderRegistry;
use EruoFood\Verification\Domain\Enum\ActorType;
use EruoFood\Verification\Domain\VerificationCase\CaseRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Catches cases a lost webhook would otherwise strand.
 *
 * Webhooks are the fast path, not a guarantee: a delivery can be dropped, a
 * deploy can land mid-flight, a provider can have an outage. Without a second
 * route to the truth, a rider whose verification actually succeeded sits
 * unverified — unable to earn — until somebody notices by hand.
 *
 * So anything still awaiting a provider decision past a configured age gets
 * polled directly. Both paths funnel through
 * {@see VerificationService::applyDecision()}, so a reconciled result is
 * interpreted identically to a pushed one and cannot drift from it.
 *
 * Also sweeps verifications whose validity has run out, which is what makes
 * expiry real rather than a column nobody acts on.
 */
final readonly class ReconciliationService
{
    public function __construct(
        private CaseRepository $cases,
        private VerificationProviderRegistry $providers,
        private VerificationService $verification,
        private TransactionManager $transactions,
        private Clock $clock,
        private LoggerInterface $logger,
        private int $reconcileAfterMinutes,
    ) {
    }

    /**
     * Poll the provider for cases stuck awaiting a decision.
     *
     * @return array{checked: int, updated: int, failed: int}
     */
    public function reconcileStalled(int $limit = 100): array
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d minutes', max(1, $this->reconcileAfterMinutes)));
        $stalled = $this->cases->stalledSince($cutoff, $limit);

        $checked = 0;
        $updated = 0;
        $failed = 0;

        foreach ($stalled as $case) {
            $reference = $case->providerReference();
            $provider = $case->provider();

            if ($reference === null || $provider === null) {
                continue;
            }

            $checked++;

            try {
                // Network call, deliberately outside the transaction below.
                $decision = $this->providers->for($provider)->fetchDecision($reference);
            } catch (Throwable $e) {
                $failed++;
                // The message may name a provider session; never the payload.
                $this->logger->warning('Verification reconciliation could not reach the provider.', [
                    'case_id' => $case->id(),
                    'provider' => $provider->value,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($decision->status === $case->status()) {
                continue;
            }

            $settled = $this->transactions->atomic(fn () => $this->verification->applyDecision(
                $case->id(),
                $decision,
                ActorType::System,
                'reconciliation',
            ));

            $this->verification->announce($settled);
            $updated++;
        }

        return ['checked' => $checked, 'updated' => $updated, 'failed' => $failed];
    }

    /**
     * Expire verifications whose validity has lapsed.
     *
     * Consumers treat expiry exactly like a loss of verification, so a rider
     * whose licence ran out stops being dispatchable without anyone intervening.
     *
     * @return array{expired: int}
     */
    public function expireLapsed(int $limit = 500): array
    {
        $now = $this->clock->now();
        $lapsed = $this->cases->expiredBy($now, $limit);
        $expired = 0;

        foreach ($lapsed as $case) {
            $settled = $this->transactions->atomic(function () use ($case) {
                $locked = $this->cases->findByIdForUpdate($case->id());
                if ($locked === null || ! $locked->hasExpiredBy($this->clock->now())) {
                    return null;
                }

                $locked->expire(ActorType::System, 'expiry-sweep', $this->clock->now());
                $this->cases->save($locked);

                return $locked;
            });

            if ($settled !== null) {
                $this->verification->announce($settled);
                $expired++;
            }
        }

        return ['expired' => $expired];
    }
}
