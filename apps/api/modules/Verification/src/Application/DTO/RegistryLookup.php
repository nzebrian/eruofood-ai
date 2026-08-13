<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\DTO;

/**
 * What a business registry says about a company.
 *
 * Distinct from an identity decision because it answers a different question:
 * whether the company exists and is in good standing, not whether the person
 * operating the account is who they claim. A full KYB needs both.
 *
 * `matched` false with `found` true means the registration exists but the name
 * we were given does not match it — a materially different situation from "no
 * such company", and one a reviewer needs to see distinctly.
 */
final readonly class RegistryLookup
{
    public function __construct(
        public bool $found,
        public bool $active,
        public bool $matched,
        public ?string $registeredName = null,
        public ?string $registrationNumber = null,
        public ?string $status = null,
        public ?string $registeredOn = null,
        /** True when the registry has no API and a human must confirm. */
        public bool $requiresManualReview = false,
        public ?string $note = null,
    ) {
    }

    public function isSatisfactory(): bool
    {
        return $this->found && $this->active && $this->matched && ! $this->requiresManualReview;
    }
}
