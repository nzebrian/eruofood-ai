<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Port;

use EruoFood\Verification\Application\DTO\RegistryLookup;

/**
 * An official business registry — Nigeria's CAC, and whatever equivalent a
 * future market has.
 *
 * Deliberately a separate port from {@see IdentityVerificationProvider}. A
 * registry answers "does this company exist and is it in good standing"; an
 * identity provider answers "is this person who they claim". Conflating them
 * would have forced CAC to masquerade as a manual-review flow and made the
 * country dimension invisible.
 *
 * Registries also differ from identity providers operationally: many have no
 * public API at all, so an implementation is allowed to answer "I validated the
 * format, a human must confirm the rest" via
 * {@see RegistryLookup::$requiresManualReview} rather than pretending.
 */
interface BusinessRegistryProvider
{
    /** ISO 3166-1 alpha-2 country this registry serves. */
    public function countryCode(): string;

    /** The issuing authority's short name, e.g. "CAC". */
    public function authority(): string;

    /** Whether the number is well-formed for this registry, checked before any call. */
    public function isWellFormed(string $registrationNumber): bool;

    /** Look the company up and report what the registry holds. */
    public function lookup(string $registrationNumber, string $registeredName): RegistryLookup;
}
