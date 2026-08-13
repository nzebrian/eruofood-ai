<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Phone;

/** Persistence port for {@see PhoneChallenge}. */
interface PhoneChallengeRepository
{
    public function nextIdentity(): string;

    public function findForUser(string $userId): ?PhoneChallenge;

    /** Locking read, so two concurrent confirmations cannot each spend the last attempt. */
    public function findForUserForUpdate(string $userId): ?PhoneChallenge;

    public function save(PhoneChallenge $challenge): void;

    /** Whether the user currently holds a confirmed number. */
    public function isVerified(string $userId): bool;
}
