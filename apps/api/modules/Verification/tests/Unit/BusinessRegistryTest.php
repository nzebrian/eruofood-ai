<?php

declare(strict_types=1);

use EruoFood\Verification\Application\Port\IdentityVerificationProvider;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\Exception\ProviderUnavailable;
use EruoFood\Verification\Infrastructure\Provider\Manual\ManualReviewProvider;
use EruoFood\Verification\Infrastructure\Provider\Mock\MockProvider;
use EruoFood\Verification\Infrastructure\Provider\Registry\CacRegistryProvider;
use EruoFood\Verification\Infrastructure\Registry\ConfigBusinessRegistryRegistry;
use EruoFood\Verification\Infrastructure\Registry\ConfigProviderRegistry;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * M24 — business registries and provider routing.
 *
 * CAC is modelled as a real registry adapter, not as a manual-review
 * placeholder: it knows Nigeria's registration formats and how to compare a
 * claimed name against a registered one. These tests hold that line, and hold
 * the country boundary — no other market may inherit an assumption that CAC
 * exists.
 */
function cac(array $apiOverrides = []): CacRegistryProvider
{
    return new CacRegistryProvider(new HttpFactory(), [
        'authority' => 'CAC',
        'number_pattern' => '/^(RC|BN|IT)[- ]?\d{4,12}$/i',
        'api' => array_replace(['base_url' => '', 'api_key' => '', 'timeout' => 5], $apiOverrides),
    ]);
}

// -------------------------------------------------------- CAC number format --

it('recognises the three registration series CAC issues', function (): void {
    $registry = cac();

    // RC = company, BN = business name, IT = incorporated trustees.
    expect($registry->isWellFormed('RC123456'))->toBeTrue()
        ->and($registry->isWellFormed('BN 1234567'))->toBeTrue()
        ->and($registry->isWellFormed('IT-98765'))->toBeTrue()
        ->and($registry->isWellFormed('rc123456'))->toBeTrue();
});

it('rejects registration numbers that are not CAC-shaped', function (): void {
    $registry = cac();

    expect($registry->isWellFormed('12345678'))->toBeFalse()
        ->and($registry->isWellFormed('XX123456'))->toBeFalse()
        ->and($registry->isWellFormed('RC12'))->toBeFalse()
        ->and($registry->isWellFormed(''))->toBeFalse();
});

it('identifies itself as the Nigerian authority', function (): void {
    expect(cac()->countryCode())->toBe('NG')->and(cac()->authority())->toBe('CAC');
});

// ------------------------------------------------------------ CAC behaviour --

it('reports a malformed number as not found without calling anywhere', function (): void {
    $lookup = cac()->lookup('NOT-A-NUMBER', 'Some Company Ltd');

    expect($lookup->found)->toBeFalse()
        ->and($lookup->requiresManualReview)->toBeFalse()
        ->and($lookup->isSatisfactory())->toBeFalse();
});

it('asks for human review — never assumes valid — when no CAC API is configured', function (): void {
    // The default state today: EruoFood has no CAC API contract. The adapter
    // must say so rather than reporting a company as verified.
    $lookup = cac()->lookup('RC123456', 'Mama Put Kitchen Limited');

    expect($lookup->requiresManualReview)->toBeTrue()
        ->and($lookup->isSatisfactory())->toBeFalse()
        ->and($lookup->found)->toBeFalse()
        ->and($lookup->note)->toContain('not configured');
});

it('confirms an active company whose name matches', function (): void {
    $http = new HttpFactory();
    $http->fake(['*' => $http->response([
        'company_name' => 'MAMA PUT KITCHEN LIMITED',
        'status' => 'ACTIVE',
        'registration_date' => '2019-03-04',
    ], 200)]);

    $registry = new CacRegistryProvider($http, [
        'authority' => 'CAC',
        'number_pattern' => '/^(RC|BN|IT)[- ]?\d{4,12}$/i',
        'api' => ['base_url' => 'https://cac.example', 'api_key' => 'k', 'timeout' => 5],
    ]);

    $lookup = $registry->lookup('RC123456', 'Mama Put Kitchen Ltd');

    // "Limited" vs "Ltd" is the kind of difference that appears between a
    // certificate and a signup form; it must not fail a legitimate merchant.
    expect($lookup->isSatisfactory())->toBeTrue()
        ->and($lookup->matched)->toBeTrue()
        ->and($lookup->active)->toBeTrue();
});

it('flags a name that genuinely belongs to a different company', function (): void {
    $http = new HttpFactory();
    $http->fake(['*' => $http->response(['company_name' => 'ENTIRELY OTHER VENTURES', 'status' => 'ACTIVE'], 200)]);

    $registry = new CacRegistryProvider($http, [
        'authority' => 'CAC',
        'number_pattern' => '/^(RC|BN|IT)[- ]?\d{4,12}$/i',
        'api' => ['base_url' => 'https://cac.example', 'api_key' => 'k', 'timeout' => 5],
    ]);

    $lookup = $registry->lookup('RC123456', 'Mama Put Kitchen Ltd');

    // Found and active, but not the claimed company — materially different from
    // "no such company", and a reviewer needs to see which.
    expect($lookup->found)->toBeTrue()
        ->and($lookup->active)->toBeTrue()
        ->and($lookup->matched)->toBeFalse()
        ->and($lookup->isSatisfactory())->toBeFalse();
});

it('reports an inactive company as unsatisfactory', function (): void {
    $http = new HttpFactory();
    $http->fake(['*' => $http->response(['company_name' => 'MAMA PUT KITCHEN LIMITED', 'status' => 'DISSOLVED'], 200)]);

    $registry = new CacRegistryProvider($http, [
        'authority' => 'CAC',
        'number_pattern' => '/^(RC|BN|IT)[- ]?\d{4,12}$/i',
        'api' => ['base_url' => 'https://cac.example', 'api_key' => 'k', 'timeout' => 5],
    ]);

    $lookup = $registry->lookup('RC123456', 'Mama Put Kitchen Limited');

    expect($lookup->found)->toBeTrue()->and($lookup->active)->toBeFalse()
        ->and($lookup->isSatisfactory())->toBeFalse();
});

it('routes a registry outage to review rather than deciding either way', function (): void {
    $http = new HttpFactory();
    $http->fake(['*' => $http->response('gateway timeout', 504)]);

    $registry = new CacRegistryProvider($http, [
        'authority' => 'CAC',
        'number_pattern' => '/^(RC|BN|IT)[- ]?\d{4,12}$/i',
        'api' => ['base_url' => 'https://cac.example', 'api_key' => 'k', 'timeout' => 5],
    ]);

    $lookup = $registry->lookup('RC123456', 'Mama Put Kitchen Limited');

    expect($lookup->requiresManualReview)->toBeTrue()->and($lookup->isSatisfactory())->toBeFalse();
});

it('reports a genuinely unregistered company as not found', function (): void {
    $http = new HttpFactory();
    $http->fake(['*' => $http->response(['detail' => 'not found'], 404)]);

    $registry = new CacRegistryProvider($http, [
        'authority' => 'CAC',
        'number_pattern' => '/^(RC|BN|IT)[- ]?\d{4,12}$/i',
        'api' => ['base_url' => 'https://cac.example', 'api_key' => 'k', 'timeout' => 5],
    ]);

    $lookup = $registry->lookup('RC999999', 'Ghost Company Ltd');

    expect($lookup->found)->toBeFalse()->and($lookup->requiresManualReview)->toBeFalse();
});

// ------------------------------------------------------- country boundaries --

it('does not assume CAC exists outside Nigeria', function (): void {
    $registries = new ConfigBusinessRegistryRegistry([
        'NG' => fn (): CacRegistryProvider => cac(),
    ]);

    expect($registries->forCountry('NG'))->not->toBeNull()
        // A market with no registry integration returns null, forcing the caller
        // to route to manual review rather than inheriting Nigeria's registry.
        ->and($registries->forCountry('GH'))->toBeNull()
        ->and($registries->forCountry('KE'))->toBeNull()
        ->and($registries->supportedCountries())->toBe(['NG']);
});

// ------------------------------------------------------------ provider routing --

it('routes a case to the provider configured for its country', function (): void {
    $registry = new ConfigProviderRegistry(
        [
            'mock' => fn (): IdentityVerificationProvider => new MockProvider(['webhook_secret' => 's']),
            'manual' => fn (): IdentityVerificationProvider => new ManualReviewProvider(),
        ],
        [
            'identity' => ['default' => 'mock', 'by_country' => []],
            'business' => ['default' => 'manual', 'by_country' => ['NG' => 'mock']],
        ],
    );

    expect($registry->resolve(CaseType::Identity, 'NG')->name())->toBe(ProviderName::Mock)
        ->and($registry->resolve(CaseType::Business, 'NG')->name())->toBe(ProviderName::Mock)
        // No NG-specific entry, so the business default applies.
        ->and($registry->resolve(CaseType::Business, 'GH')->name())->toBe(ProviderName::Manual);
});

it('raises rather than falling back when no provider is configured', function (): void {
    $registry = new ConfigProviderRegistry([], ['identity' => ['default' => '']]);

    // Falling back to "no provider, therefore fine" is exactly how an
    // unverifiable subject would slip through.
    expect(fn () => $registry->resolve(CaseType::Identity, 'NG'))->toThrow(ProviderUnavailable::class);
});

it('raises when a configured provider is not registered', function (): void {
    $registry = new ConfigProviderRegistry([], ['identity' => ['default' => 'didit']]);

    expect(fn () => $registry->resolve(CaseType::Identity, 'NG'))->toThrow(ProviderUnavailable::class);
});

// ------------------------------------------------------ manual review fallback --

it('sends every manual case straight to a human, deciding nothing itself', function (): void {
    $manual = new ManualReviewProvider();

    $session = $manual->createSession(new EruoFood\Verification\Application\DTO\VerificationRequest(
        caseId: 'case-1',
        subjectType: EruoFood\Verification\Domain\Enum\SubjectType::Business,
        caseType: CaseType::Business,
        countryCode: 'GH',
    ));

    expect($session->status)->toBe(VerificationStatus::RequiresReview)
        // Nothing for the subject to complete.
        ->and($session->hostedUrl)->toBeNull()
        ->and($manual->fetchDecision('x')->status)->toBe(VerificationStatus::RequiresReview);
});

it('refuses any callback to the manual provider', function (): void {
    // Accepting one would be an unauthenticated route to changing verification
    // state, since nothing external ever legitimately calls back for a manual case.
    expect(fn () => (new ManualReviewProvider())->parseWebhook('{}', EruoFood\Verification\Application\DTO\WebhookHeaders::fromArray([])))
        ->toThrow(EruoFood\Verification\Domain\Exception\WebhookRejected::class);
});

it('is available for every case type so nothing falls through unverified', function (): void {
    $manual = new ManualReviewProvider();

    expect($manual->supports(CaseType::Identity, 'NG'))->toBeTrue()
        ->and($manual->supports(CaseType::Business, 'ZZ'))->toBeTrue();
});
