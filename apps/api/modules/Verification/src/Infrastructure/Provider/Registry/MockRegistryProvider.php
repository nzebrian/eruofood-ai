<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Provider\Registry;

use EruoFood\Verification\Application\DTO\RegistryLookup;
use EruoFood\Verification\Application\Port\BusinessRegistryProvider;

/**
 * Offline registry for tests and local development.
 *
 * Steered by the registration number so a test can request an outcome without
 * a fake: "RC000000" is not found, "RC111111" is inactive, "RC222222" needs
 * review, anything else well-formed resolves cleanly. The name-matching rule is
 * the real one, so name-mismatch behaviour is genuinely exercised.
 */
final readonly class MockRegistryProvider implements BusinessRegistryProvider
{
    public function __construct(private string $countryCode = 'NG')
    {
    }

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    public function authority(): string
    {
        return 'MOCK-REGISTRY';
    }

    public function isWellFormed(string $registrationNumber): bool
    {
        return preg_match('/^(RC|BN|IT)[- ]?\d{4,12}$/i', trim($registrationNumber)) === 1;
    }

    public function lookup(string $registrationNumber, string $registeredName): RegistryLookup
    {
        $number = strtoupper(preg_replace('/[\s-]+/', '', trim($registrationNumber)) ?? '');

        if (! $this->isWellFormed($number)) {
            return new RegistryLookup(false, false, false, null, $number, null, null, false, 'Malformed registration number.');
        }

        return match ($number) {
            'RC000000' => new RegistryLookup(false, false, false, null, $number, null, null, false, 'Not found in the registry.'),
            'RC111111' => new RegistryLookup(true, false, true, $registeredName, $number, 'INACTIVE', null, false, 'Registry reports the company inactive.'),
            'RC222222' => new RegistryLookup(false, false, false, null, $number, null, null, true, 'Registry unavailable; needs review.'),
            'RC333333' => new RegistryLookup(true, true, false, 'A Completely Different Company', $number, 'ACTIVE'),
            default => new RegistryLookup(true, true, true, $registeredName, $number, 'ACTIVE', '2020-01-01'),
        };
    }
}
