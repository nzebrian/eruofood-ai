<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Port;

use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\ProviderName;

/**
 * Chooses which provider handles a case.
 *
 * Routing is by (case type, country) because those are the two things that
 * actually determine capability: identity verification is global, business
 * registries are not. Configuration decides; no business rule names a provider.
 */
interface VerificationProviderRegistry
{
    /**
     * @throws \EruoFood\Verification\Domain\Exception\ProviderUnavailable
     */
    public function for(ProviderName $name): IdentityVerificationProvider;

    /**
     * The provider configured for this case type and country.
     *
     * @throws \EruoFood\Verification\Domain\Exception\ProviderUnavailable when
     *                                                                     none is configured — never a silent fallback that would let an
     *                                                                     unverifiable subject through
     */
    public function resolve(CaseType $caseType, string $countryCode): IdentityVerificationProvider;
}
