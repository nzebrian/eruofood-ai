<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Service;

use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Verification\Application\Port\PhoneVerificationSender;
use EruoFood\Verification\Domain\Event\PhoneVerified;
use EruoFood\Verification\Domain\Exception\VerificationInvalidState;
use EruoFood\Verification\Domain\Phone\PhoneChallenge;
use EruoFood\Verification\Domain\Phone\PhoneChallengeRepository;

/**
 * Phone confirmation — the first rung of progressive verification.
 *
 * The point of this rung is that it can be asked for at the moment it is
 * needed. A customer registers and orders with no verification at all; a
 * confirmed number is demanded only when they do something where a
 * throwaway account would be a problem. Making that cheap is what keeps the
 * platform from having to choose between friction at sign-up and no assurance
 * at all.
 *
 * The code is hashed before it is stored and compared by hash, so the stored row
 * cannot be used to complete the challenge. Confirmation happens under a row
 * lock, which is what stops parallel guesses from each spending "the last"
 * attempt and getting more tries than the limit allows.
 */
final readonly class PhoneVerificationService
{
    public function __construct(
        private PhoneChallengeRepository $challenges,
        private PhoneVerificationSender $sender,
        private EventBus $events,
        private TransactionManager $transactions,
        private Clock $clock,
        private int $codeTtlSeconds,
        private int $maxAttempts,
    ) {
    }

    /**
     * Issue a code to a number.
     *
     * Re-requesting replaces the outstanding code rather than adding a second
     * valid one: two live codes double the guessing surface for no benefit.
     */
    public function request(string $userId, string $phone): void
    {
        $now = $this->clock->now();
        $code = $this->generateCode();
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->codeTtlSeconds));

        $this->transactions->atomic(function () use ($userId, $phone, $hash, $expiresAt, $now): void {
            $existing = $this->challenges->findForUserForUpdate($userId);

            $challenge = $existing === null
                ? PhoneChallenge::issue($this->challenges->nextIdentity(), $userId, $phone, $hash, $expiresAt, $now)
                : $existing->reissue($phone, $hash, $expiresAt, $now);

            $this->challenges->save($challenge);
        });

        // Sent outside the transaction: an SMS cannot be rolled back, so it must
        // not be dispatched until the code it refers to is durably stored.
        $this->sender->send($phone, $code);
    }

    /**
     * Spend an attempt. Returns true when the number is now confirmed.
     *
     * @throws VerificationInvalidState when there is nothing to confirm, the code
     *                                  has expired, or the attempt limit is spent
     */
    public function confirm(string $userId, string $code): bool
    {
        $now = $this->clock->now();

        $confirmed = $this->transactions->atomic(function () use ($userId, $code, $now): bool {
            $challenge = $this->challenges->findForUserForUpdate($userId)
                ?? throw new VerificationInvalidState('Request a verification code first.');

            $wasVerified = $challenge->isVerified();

            $matched = $challenge->confirm(
                $code,
                $this->maxAttempts,
                static fn (string $plain, string $hash): bool => password_verify($plain, $hash),
                $now,
            );

            // Saved either way — a failed guess must still cost an attempt.
            $this->challenges->save($challenge);

            // Only a transition publishes, so a repeated submit of a correct
            // code does not re-announce the level change.
            return $matched && ! $wasVerified;
        });

        if ($confirmed) {
            $this->events->publish(new PhoneVerified($userId));
        }

        return $confirmed || $this->challenges->isVerified($userId);
    }

    public function isVerified(string $userId): bool
    {
        return $this->challenges->isVerified($userId);
    }

    /**
     * A six-digit code from a cryptographic source.
     *
     * `random_int` rather than `rand`: a predictable code is the same as no code.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
