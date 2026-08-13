<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Provider\Registry;

use EruoFood\Verification\Application\DTO\RegistryLookup;
use EruoFood\Verification\Application\Port\BusinessRegistryProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Nigeria's Corporate Affairs Commission — the registry EruoFood's Nigerian
 * merchants are actually registered with.
 *
 * This is a real registry adapter, not a manual-review placeholder. It knows
 * what a CAC registration number looks like, which prefixes the Commission
 * issues and what they mean, and how to compare a claimed trading name against
 * a registered one. That knowledge belongs to Nigeria and lives here, so no
 * other market inherits an assumption that CAC exists.
 *
 * **On the API.** CAC does not publish an open verification API, and EruoFood
 * has no contract with an aggregator yet. Rather than pretend, the adapter has
 * two modes:
 *
 * - **Configured** (`registries.NG.api.base_url` set) — calls the endpoint and
 *   reports what the registry actually holds.
 * - **Unconfigured** (the default) — validates the number's structure, then
 *   returns `requiresManualReview: true`. It never claims a company was
 *   verified when nothing checked it.
 *
 * Switching modes is configuration. No business logic changes, because
 * {@see \EruoFood\Verification\Application\Service\BusinessVerificationService}
 * reads the result rather than knowing how it was obtained.
 */
final readonly class CacRegistryProvider implements BusinessRegistryProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private HttpFactory $http,
        private array $config,
    ) {
    }

    public function countryCode(): string
    {
        return 'NG';
    }

    public function authority(): string
    {
        return (string) ($this->config['authority'] ?? 'CAC');
    }

    /**
     * CAC issues three registration series, and the prefix says what kind of
     * entity it is:
     *
     *   RC — a registered company (limited by shares or guarantee)
     *   BN — a registered business name (sole proprietorship, partnership)
     *   IT — incorporated trustees (associations, NGOs)
     *
     * Checking the shape before any network call keeps obvious typos out of the
     * review queue and out of a paid registry lookup.
     */
    public function isWellFormed(string $registrationNumber): bool
    {
        $pattern = (string) ($this->config['number_pattern'] ?? '/^(RC|BN|IT)[- ]?\d{4,12}$/i');

        return preg_match($pattern, trim($registrationNumber)) === 1;
    }

    public function lookup(string $registrationNumber, string $registeredName): RegistryLookup
    {
        $number = $this->canonicalise($registrationNumber);

        if (! $this->isWellFormed($number)) {
            return new RegistryLookup(
                found: false,
                active: false,
                matched: false,
                registrationNumber: $number,
                note: 'The registration number is not a valid CAC format (expected RC, BN or IT followed by digits).',
            );
        }

        $baseUrl = (string) ($this->config['api']['base_url'] ?? '');

        if ($baseUrl === '') {
            // No registry integration provisioned. Say so plainly and route to a
            // human — the one thing that must not happen is reporting a company
            // as verified because nothing was able to check it.
            return new RegistryLookup(
                found: false,
                active: false,
                matched: false,
                registrationNumber: $number,
                requiresManualReview: true,
                note: 'CAC registry lookup is not configured; the registration format is valid and a reviewer must confirm the certificate.',
            );
        }

        try {
            $response = $this->http
                ->baseUrl($baseUrl)
                ->withHeaders(['Accept' => 'application/json'])
                ->withToken((string) ($this->config['api']['api_key'] ?? ''))
                ->timeout((int) ($this->config['api']['timeout'] ?? 30))
                ->get('/company/'.urlencode($number));
        } catch (Throwable) {
            // A registry outage must not decide the case either way.
            return new RegistryLookup(
                found: false,
                active: false,
                matched: false,
                registrationNumber: $number,
                requiresManualReview: true,
                note: 'The CAC registry could not be reached; the case needs a reviewer.',
            );
        }

        if ($response->status() === 404) {
            return new RegistryLookup(
                found: false,
                active: false,
                matched: false,
                registrationNumber: $number,
                note: 'No company is registered under this number.',
            );
        }

        if (! $response->successful()) {
            return new RegistryLookup(
                found: false,
                active: false,
                matched: false,
                registrationNumber: $number,
                requiresManualReview: true,
                note: sprintf('The CAC registry returned an unexpected response (HTTP %d).', $response->status()),
            );
        }

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();

        $officialName = $this->string($data, 'company_name') ?: $this->string($data, 'name');
        $status = $this->string($data, 'status') ?: $this->string($data, 'company_status');
        $active = $status === '' || $this->looksActive($status);

        return new RegistryLookup(
            found: true,
            active: $active,
            matched: $this->namesMatch($officialName, $registeredName),
            registeredName: $officialName !== '' ? $officialName : null,
            registrationNumber: $number,
            status: $status !== '' ? $status : null,
            registeredOn: $this->string($data, 'registration_date') ?: null,
            note: $active ? null : sprintf('The registry reports this company as "%s".', $status),
        );
    }

    /** Normalise "rc 12345" and "RC-12345" to "RC12345". */
    private function canonicalise(string $registrationNumber): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', trim($registrationNumber)) ?? '');
    }

    /**
     * Compare a claimed name with the registered one.
     *
     * Deliberately tolerant of the things that differ harmlessly between a
     * certificate and a form — case, punctuation, spacing, and the suffixes
     * ("LIMITED", "LTD", "PLC", "ENTERPRISES") people include inconsistently.
     * A genuinely different company still fails, and anything short of an exact
     * match after normalisation is left for a reviewer rather than guessed at
     * with a fuzzy score.
     */
    private function namesMatch(string $official, string $claimed): bool
    {
        if ($official === '' || $claimed === '') {
            return false;
        }

        return $this->normaliseName($official) === $this->normaliseName($claimed);
    }

    private function normaliseName(string $name): string
    {
        $upper = strtoupper($name);
        $alphanumeric = preg_replace('/[^A-Z0-9 ]+/', ' ', $upper) ?? '';
        $collapsed = trim(preg_replace('/\s+/', ' ', $alphanumeric) ?? '');

        $suffixes = ['LIMITED', 'LTD', 'PLC', 'INCORPORATED', 'INC', 'ENTERPRISES', 'ENTERPRISE', 'VENTURES', 'NIGERIA', 'NIG'];
        $words = array_filter(
            explode(' ', $collapsed),
            static fn (string $word): bool => $word !== '' && ! in_array($word, $suffixes, true),
        );

        return implode(' ', $words);
    }

    private function looksActive(string $status): bool
    {
        $normalised = strtoupper(trim($status));

        return in_array($normalised, ['ACTIVE', 'REGISTERED', 'IN GOOD STANDING', 'LIVE'], true);
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
