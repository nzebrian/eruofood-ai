<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Attempt;

/** Persistence port for {@see VerificationAttempt}. */
interface AttemptRepository
{
    public function nextIdentity(): string;

    public function findByProviderReference(string $providerReference): ?VerificationAttempt;

    /** @return list<VerificationAttempt> newest first */
    public function forCase(string $caseId): array;

    public function save(VerificationAttempt $attempt): void;
}
