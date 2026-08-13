<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Phone;

use DateTimeImmutable;
use EruoFood\Verification\Domain\Exception\VerificationInvalidState;

/**
 * A one-time code sent to a phone number, and the rules for spending it.
 *
 * This is the cheap rung of progressive verification: it costs a customer
 * nothing and takes seconds, so it can be demanded at the point a small amount
 * of assurance is genuinely needed rather than at registration, where it would
 * simply cost sign-ups.
 *
 * The code itself is never stored — only a hash — so a leaked database row does
 * not let anybody complete somebody else's verification. Attempts are counted on
 * the row rather than in a cache, because a rate limit that resets when the
 * cache is flushed is not a rate limit.
 */
final class PhoneChallenge
{
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private readonly string $phone,
        private string $codeHash,
        private DateTimeImmutable $expiresAt,
        private int $attempts,
        private ?DateTimeImmutable $verifiedAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function issue(
        string $id,
        string $userId,
        string $phone,
        string $codeHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $userId, $phone, $codeHash, $expiresAt, 0, null, $now, $now);
    }

    public static function reconstitute(
        string $id,
        string $userId,
        string $phone,
        string $codeHash,
        DateTimeImmutable $expiresAt,
        int $attempts,
        ?DateTimeImmutable $verifiedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $userId, $phone, $codeHash, $expiresAt, $attempts, $verifiedAt, $createdAt, $updatedAt);
    }

    /**
     * Re-issue against the same row when a customer asks for another code.
     *
     * Deliberately resets the attempt counter along with the code: the counter
     * protects a *code* from being guessed, and this is a different code. What
     * stops someone from resetting it endlessly is the request rate limit on the
     * route, which is the right place for that concern.
     */
    public function reissue(string $phone, string $codeHash, DateTimeImmutable $expiresAt, DateTimeImmutable $now): self
    {
        return new self($this->id, $this->userId, $phone, $codeHash, $expiresAt, 0, null, $this->createdAt, $now);
    }

    /**
     * Spend an attempt against this challenge.
     *
     * @param callable(string, string): bool $matches hash comparison, injected so
     *                                                the domain never depends on a hashing implementation
     *
     * @throws VerificationInvalidState when expired or out of attempts
     */
    public function confirm(string $code, int $maxAttempts, callable $matches, DateTimeImmutable $now): bool
    {
        if ($this->verifiedAt !== null) {
            return true; // already confirmed; a repeated submit is not an error
        }

        if ($now >= $this->expiresAt) {
            throw new VerificationInvalidState('This code has expired. Request a new one.');
        }

        if ($this->attempts >= $maxAttempts) {
            throw new VerificationInvalidState('Too many incorrect attempts. Request a new code.');
        }

        // Counted before the comparison, so a wrong guess costs an attempt even
        // if the process dies immediately afterwards.
        $this->attempts++;
        $this->updatedAt = $now;

        if (! $matches($code, $this->codeHash)) {
            return false;
        }

        $this->verifiedAt = $now;

        return true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function codeHash(): string
    {
        return $this->codeHash;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function verifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
