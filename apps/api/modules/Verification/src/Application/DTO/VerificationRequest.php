<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\DTO;

use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\SubjectType;

/**
 * What EruoFood asks a provider to verify — in EruoFood's vocabulary.
 *
 * Nothing here is provider-shaped: no workflow ids, no vendor field names, no
 * endpoint concerns. An adapter translates this into whatever its API expects,
 * which is what lets a second provider be added without the domain noticing.
 *
 * `caseId` is what gets handed to the provider as their correlation value. It
 * is an opaque UUID rather than a user id, so nothing about our internal
 * identity model leaves the platform.
 */
final readonly class VerificationRequest
{
    /**
     * @param list<string> $requiredChecks e.g. ['document', 'liveness', 'face_match', 'driving_licence']
     */
    public function __construct(
        public string $caseId,
        public SubjectType $subjectType,
        public CaseType $caseType,
        public string $countryCode,
        public array $requiredChecks = [],
        public ?string $callbackUrl = null,
        /** Business fields, populated only for CaseType::Business. */
        public ?string $registrationNumber = null,
        public ?string $registeredName = null,
    ) {
    }

    public function requires(string $check): bool
    {
        return in_array($check, $this->requiredChecks, true);
    }
}
