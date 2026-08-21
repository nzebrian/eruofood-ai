<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Environment\EnvironmentSnapshot;

/**
 * Guards the committed environment templates against each other.
 *
 * {@see EnvironmentPolicyTest} judges one environment at a time from inside it.
 * These are the questions that only make sense when you can see all three at
 * once: does staging share production's bucket, does development resolve to a
 * live gateway, does anything reach production with a placeholder still in it.
 *
 * The templates are the only artefact in this repository that describes
 * production, so they are the only place these mistakes can be caught before
 * somebody makes them in a secret manager.
 */
function envTemplate(string $relativePath): array
{
    // base_path() is <repo>/apps/api; the templates live across the whole
    // repository, so climb two levels rather than counting directories up from
    // this test file.
    $path = dirname(base_path(), 2).'/'.$relativePath;

    expect(file_exists($path))->toBeTrue("Missing environment template: {$relativePath}");

    $values = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        // Later assignments win, matching dotenv, and inline comments are
        // stripped so `DB_SSLMODE=require   # mandatory` reads as `require`.
        $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
        $values[trim($key)] = trim($value, "\"'");
    }

    return $values;
}

beforeEach(function (): void {
    $this->development = envTemplate('apps/api/.env.example');
    $this->staging = envTemplate('infra/env/staging.env.example');
    $this->production = envTemplate('infra/env/production.env.example');
});

describe('the payment provider is stated, never inherited', function (): void {
    it('pins a provider in every environment template', function (): void {
        // `config/payments.php` falls back to a live gateway for everything but
        // APP_ENV=testing. A template that omits this does not mean "no
        // provider"; it means Paystack.
        foreach (['development', 'staging', 'production'] as $name) {
            expect(array_key_exists('PAYMENTS_PROVIDER', $this->{$name}))->toBeTrue(
                "The {$name} template does not pin PAYMENTS_PROVIDER, so it resolves to a live gateway.",
            );
        }
    });

    it('keeps every non-production template off a live gateway', function (): void {
        foreach (['development', 'staging'] as $name) {
            $provider = strtolower($this->{$name}['PAYMENTS_PROVIDER'] ?? '');

            expect($provider)->toBeIn(array_merge(
                EnvironmentSnapshot::offlinePaymentProviders(),
                EnvironmentSnapshot::sandboxPaymentProviders(),
            ), "The {$name} template points at a live gateway.");
        }
    });

    it('gives production a live gateway', function (): void {
        expect(strtolower($this->production['PAYMENTS_PROVIDER']))
            ->not->toBeIn(EnvironmentSnapshot::offlinePaymentProviders());
    });
});

describe('financial switches ship off', function (): void {
    it('declares every settlement flag false in the deployed templates', function (): void {
        $flags = [
            'FLAG_SETTLEMENT_ACCRUAL',
            'FLAG_SETTLEMENT_ACCRUAL_POSTING',
            'FLAG_SETTLEMENT_COMPUTE',
            'FLAG_SETTLEMENT_RECONCILE',
            'FLAG_SETTLEMENT_AUTO_APPROVE',
            'FLAG_SETTLEMENT_EXECUTE',
            'FLAG_SETTLEMENT_NEW_FLOW',
        ];

        foreach (['staging', 'production'] as $name) {
            foreach ($flags as $flag) {
                expect(array_key_exists($flag, $this->{$name}))->toBeTrue("{$name} does not declare {$flag}");
                expect(strtolower($this->{$name}[$flag]))->toBe('false', "{$name} enables {$flag}");
            }
        }
    });

    it('keeps the dispatch engine off in the deployed templates', function (): void {
        foreach (['staging', 'production'] as $name) {
            expect(strtolower($this->{$name}['DISPATCH_ENGINE_ENABLED'] ?? ''))->toBe('false');
        }
    });
});

describe('environments do not share stateful resources', function (): void {
    it('gives staging and production different database names', function (): void {
        // The last line of defence when someone points staging at the wrong
        // host: a name mismatch turns a catastrophe into a connection error.
        expect($this->staging['DB_DATABASE'])->not->toBe($this->production['DB_DATABASE']);
    });

    it('gives development a database name that is not production\'s', function (): void {
        expect($this->development['DB_DATABASE'])->not->toBe($this->production['DB_DATABASE']);
    });

    it('does not hard-code a shared object-storage bucket', function (): void {
        $productionBucket = $this->production['AWS_BUCKET'] ?? '__SET__';

        foreach (['development', 'staging'] as $name) {
            $bucket = $this->{$name}['AWS_BUCKET'] ?? '';
            if ($productionBucket === '__SET__' || $bucket === '__SET__') {
                continue; // Both are injected at deploy time; nothing to collide.
            }
            expect($bucket)->not->toBe($productionBucket, "{$name} shares production's bucket.");
        }
    });

    it('namespaces Redis and cache keys per environment', function (string $key): void {
        // Laravel derives these from APP_NAME when unset. APP_NAME is branding,
        // and branding is identical across environments more often than not —
        // which silently puts two environments' sessions, locks and queued jobs
        // in the same keyspace the first time they share an instance.
        $values = [];

        foreach (['development', 'staging', 'production'] as $name) {
            expect(array_key_exists($key, $this->{$name}))->toBeTrue("{$name} does not set {$key}");
            $values[] = $this->{$name}[$key];
        }

        expect(array_unique($values))->toHaveCount(3);
    })->with(['REDIS_PREFIX', 'CACHE_PREFIX']);
});

describe('deployed templates demand real credentials', function (): void {
    it('never carries a concrete secret into a deployed template', function (): void {
        // Deployed templates must say `__SET__` and nothing else: a committed
        // value is either a real leaked secret or a default somebody will ship.
        $mustBeInjected = [
            'APP_KEY', 'DB_PASSWORD', 'DB_USERNAME', 'REDIS_PASSWORD',
            'JWT_SECRET', 'MAIL_PASSWORD', 'AWS_SECRET_ACCESS_KEY',
        ];

        foreach (['staging', 'production'] as $name) {
            foreach ($mustBeInjected as $key) {
                if (! array_key_exists($key, $this->{$name})) {
                    continue;
                }
                expect($this->{$name}[$key])->toBe(
                    '__SET__',
                    "{$name} carries a literal value for {$key} instead of injecting it.",
                );
            }
        }
    });

    it('requires TLS to the database in every deployed template', function (): void {
        foreach (['staging', 'production'] as $name) {
            expect(strtolower($this->{$name}['DB_SSLMODE'] ?? ''))
                ->toBeIn(['require', 'verify-ca', 'verify-full']);
        }
    });

    it('keeps debug output off in every deployed template', function (): void {
        foreach (['staging', 'production'] as $name) {
            expect(strtolower($this->{$name}['APP_DEBUG'] ?? 'true'))->toBe('false');
            expect(strtolower($this->{$name}['LOG_LEVEL'] ?? 'debug'))->not->toBe('debug');
        }
    });

    it('names the environment it configures', function (): void {
        expect($this->development['APP_ENV'])->toBe('local')
            ->and($this->staging['APP_ENV'])->toBe('staging')
            ->and($this->production['APP_ENV'])->toBe('production');
    });
});
