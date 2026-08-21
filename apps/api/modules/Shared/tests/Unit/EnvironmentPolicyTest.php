<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Environment\DeploymentEnvironment;
use EruoFood\Shared\Domain\Environment\EnvironmentPolicy;
use EruoFood\Shared\Domain\Environment\EnvironmentSnapshot;
use EruoFood\Shared\Domain\Environment\FindingSeverity;

/**
 * The rules that stand between a configuration mistake and a real transfer.
 *
 * Every dangerous configuration here is a plain array rather than a mutated
 * `config()`, so the suite can rehearse "production with debug on" without any
 * part of it actually becoming production.
 */
function snapshot(array $overrides = []): EnvironmentSnapshot
{
    $defaults = [
        'appEnvRaw' => 'production',
        'appDebug' => false,
        'appKey' => 'base64:'.str_repeat('a', 40),
        'appName' => 'EruoFood AI',
        'logLevel' => 'warning',
        'dbConnection' => 'pgsql',
        'dbDatabase' => 'eruofood',
        'dbPassword' => 'a-real-secret',
        'dbSslMode' => 'require',
        'redisHost' => 'redis.internal',
        'redisPassword' => 'a-real-secret',
        'redisScheme' => 'tls',
        'redisPrefix' => 'eruofood_prod_',
        'queueConnection' => 'redis',
        'sessionSecureCookie' => true,
        'filesystemDisk' => 's3',
        'paymentsProvider' => 'paystack',
        'paymentsProviderPinned' => true,
        'flags' => [],
        'scheduledTasks' => [],
    ];

    return new EnvironmentSnapshot(...array_merge($defaults, $overrides));
}

/** @return list<string> */
function codes(array $findings, ?FindingSeverity $severity = null): array
{
    $filtered = $severity === null
        ? $findings
        : array_filter($findings, static fn ($f): bool => $f->severity === $severity);

    return array_values(array_map(static fn ($f): string => $f->code, $filtered));
}

beforeEach(function (): void {
    $this->policy = new EnvironmentPolicy();
});

it('passes a correctly configured production deployment', function (): void {
    expect(codes($this->policy->evaluate(snapshot())))->toBe([]);
});

it('fails closed when APP_ENV is not a name it recognises', function (): void {
    // A typo must not read as "not production" and quietly relax every
    // production-only rule at once.
    $findings = $this->policy->evaluate(snapshot(['appEnvRaw' => 'prod']));

    expect(codes($findings))->toBe(['ENV_UNRECOGNISED'])
        ->and($findings[0]->severity)->toBe(FindingSeverity::Error);
});

it('treats an empty APP_ENV as unrecognised rather than defaulting', function (): void {
    expect(codes($this->policy->evaluate(snapshot(['appEnvRaw' => '']))))->toBe(['ENV_UNRECOGNISED']);
});

it('rejects debug mode in production', function (): void {
    expect(codes($this->policy->evaluate(snapshot(['appDebug' => true]))))
        ->toContain('APP_DEBUG_IN_PRODUCTION');
});

it('rejects a missing application key in a deployed environment', function (string $env): void {
    expect(codes($this->policy->evaluate(snapshot(['appEnvRaw' => $env, 'appKey' => null, 'paymentsProvider' => $env === 'production' ? 'paystack' : 'mock']))))
        ->toContain('APP_KEY_MISSING');
})->with(['staging', 'production']);

it('rejects debug logging in a deployed environment', function (): void {
    expect(codes($this->policy->evaluate(snapshot(['logLevel' => 'debug']))))
        ->toContain('LOG_LEVEL_DEBUG');
});

it('rejects an insecure session cookie in a deployed environment', function (): void {
    expect(codes($this->policy->evaluate(snapshot(['sessionSecureCookie' => false]))))
        ->toContain('SESSION_COOKIE_INSECURE');
});

it('rejects container-local storage in a deployed environment', function (): void {
    expect(codes($this->policy->evaluate(snapshot(['filesystemDisk' => 'local']))))
        ->toContain('FILESYSTEM_LOCAL');
});

it('rejects the sync queue in production', function (): void {
    expect(codes($this->policy->evaluate(snapshot(['queueConnection' => 'sync']))))
        ->toContain('QUEUE_SYNC_IN_PRODUCTION');
});

describe('payment provider separation', function (): void {
    it('rejects an unpinned provider in a deployed environment', function (): void {
        // M27's CI failure, as a rule: nothing was misconfigured, nothing was
        // stated either, and the config default was a live gateway.
        $findings = $this->policy->evaluate(snapshot(['paymentsProviderPinned' => false]));

        expect(codes($findings, FindingSeverity::Error))->toContain('PAYMENTS_UNPINNED');
    });

    it('only warns about an unpinned provider outside a deployed environment', function (): void {
        $findings = $this->policy->evaluate(snapshot([
            'appEnvRaw' => 'local',
            'paymentsProvider' => 'mock',
            'paymentsProviderPinned' => false,
            'dbSslMode' => 'disable',
        ]));

        expect(codes($findings, FindingSeverity::Warning))->toContain('PAYMENTS_UNPINNED')
            ->and(codes($findings, FindingSeverity::Error))->not->toContain('PAYMENTS_UNPINNED');
    });

    it('rejects a live provider anywhere but production', function (string $env): void {
        expect(codes($this->policy->evaluate(snapshot([
            'appEnvRaw' => $env,
            'paymentsProvider' => 'paystack',
        ]))))->toContain('PAYMENTS_LIVE_OUTSIDE_PRODUCTION');
    })->with(['local', 'testing', 'staging']);

    it('treats an unknown provider name as live rather than assuming it is safe', function (): void {
        expect(codes($this->policy->evaluate(snapshot([
            'appEnvRaw' => 'staging',
            'paymentsProvider' => 'some_new_gateway',
        ]))))->toContain('PAYMENTS_LIVE_OUTSIDE_PRODUCTION');
    });

    it('treats an empty provider as live rather than as absent', function (): void {
        expect(codes($this->policy->evaluate(snapshot([
            'appEnvRaw' => 'staging',
            'paymentsProvider' => '',
        ]))))->toContain('PAYMENTS_LIVE_OUTSIDE_PRODUCTION');
    });

    it('rejects the offline provider in production', function (): void {
        // The inverse failure: the platform records payouts that no bank made.
        expect(codes($this->policy->evaluate(snapshot(['paymentsProvider' => 'mock']))))
            ->toContain('PAYMENTS_OFFLINE_IN_PRODUCTION');
    });

    it('accepts the offline provider in staging', function (): void {
        expect(codes($this->policy->evaluate(snapshot([
            'appEnvRaw' => 'staging',
            'paymentsProvider' => 'mock',
        ]))))->toBe([]);
    });
});

describe('persistence separation', function (): void {
    it('rejects a template placeholder left in a database password', function (string $placeholder): void {
        expect(codes($this->policy->evaluate(snapshot(['dbPassword' => $placeholder]))))
            ->toContain('DB_PASSWORD_PLACEHOLDER');
    })->with(['__SET__', '', 'change_me_in_local_only']);

    it('rejects a database connection that does not require TLS', function (): void {
        expect(codes($this->policy->evaluate(snapshot(['dbSslMode' => 'disable']))))
            ->toContain('DB_TLS_NOT_REQUIRED');
    });

    it('accepts stronger TLS modes than require', function (string $mode): void {
        expect(codes($this->policy->evaluate(snapshot(['dbSslMode' => $mode]))))
            ->not->toContain('DB_TLS_NOT_REQUIRED');
    })->with(['require', 'verify-ca', 'verify-full']);

    it('rejects an unauthenticated Redis in a deployed environment', function (): void {
        expect(codes($this->policy->evaluate(snapshot(['redisPassword' => '']))))
            ->toContain('REDIS_UNAUTHENTICATED');
    });

    it('rejects unprefixed Redis keys, which let two environments share a queue', function (): void {
        expect(codes($this->policy->evaluate(snapshot(['redisPrefix' => '']))))
            ->toContain('REDIS_PREFIX_MISSING');
    });

    it('warns rather than fails when Redis is not using TLS', function (): void {
        $findings = $this->policy->evaluate(snapshot(['redisScheme' => 'tcp']));

        expect(codes($findings, FindingSeverity::Warning))->toContain('REDIS_TLS_NOT_ENABLED')
            ->and(codes($findings, FindingSeverity::Error))->toBe([]);
    });

    it('does not apply deployed-environment persistence rules to local', function (): void {
        expect(codes($this->policy->evaluate(snapshot([
            'appEnvRaw' => 'local',
            'paymentsProvider' => 'mock',
            'dbPassword' => 'change_me_in_local_only',
            'dbSslMode' => 'disable',
            'redisPassword' => '',
        ]))))->toBe([]);
    });
});

describe('settlement activation order', function (): void {
    it('rejects posting accruals before accruals are being recorded', function (): void {
        expect(codes($this->policy->evaluate(snapshot([
            'flags' => ['settlement.accrual' => false, 'settlement.accrual_posting' => true],
        ]))))->toContain('SETTLEMENT_ORDER_VIOLATED');
    });

    it('rejects executing payouts before anything computes them', function (): void {
        expect(codes($this->policy->evaluate(snapshot([
            'flags' => [
                'settlement.accrual' => true,
                'settlement.accrual_posting' => true,
                'settlement.compute' => false,
                'settlement.execute' => true,
            ],
        ]))))->toContain('SETTLEMENT_ORDER_VIOLATED');
    });

    it('accepts the full pipeline enabled in order', function (): void {
        expect(codes($this->policy->evaluate(snapshot([
            'flags' => [
                'settlement.accrual' => true,
                'settlement.accrual_posting' => true,
                'settlement.compute' => true,
                'settlement.execute' => true,
            ],
        ]))))->toBe([]);
    });

    it('accepts a partially enabled pipeline that stops early', function (): void {
        expect(codes($this->policy->evaluate(snapshot([
            'flags' => ['settlement.accrual' => true],
        ]))))->toBe([]);
    });
});

describe('the combinations that lose money', function (): void {
    it('rejects executing payouts outside production against a live provider', function (): void {
        $findings = $this->policy->evaluate(snapshot([
            'appEnvRaw' => 'staging',
            'paymentsProvider' => 'paystack',
            'flags' => [
                'settlement.accrual' => true,
                'settlement.accrual_posting' => true,
                'settlement.compute' => true,
                'settlement.execute' => true,
            ],
        ]));

        expect(codes($findings))->toContain('SETTLEMENT_EXECUTE_LIVE_OUTSIDE_PRODUCTION');
    });

    it('rejects executing payouts in production against the offline provider', function (): void {
        // Merchants marked paid; no transfer ever left the bank.
        $findings = $this->policy->evaluate(snapshot([
            'paymentsProvider' => 'mock',
            'flags' => [
                'settlement.accrual' => true,
                'settlement.accrual_posting' => true,
                'settlement.compute' => true,
                'settlement.execute' => true,
            ],
        ]));

        expect(codes($findings))->toContain('SETTLEMENT_EXECUTE_AGAINST_OFFLINE_PROVIDER');
    });

    it('warns when automatic approval removes the four-eyes rule', function (): void {
        $findings = $this->policy->evaluate(snapshot([
            'flags' => ['settlement.auto_approve' => true],
        ]));

        expect(codes($findings, FindingSeverity::Warning))->toContain('SETTLEMENT_FOUR_EYES_RELAXED')
            ->and(codes($findings, FindingSeverity::Error))->toBe([]);
    });

    it('warns when a financial job is running on a timer', function (): void {
        $findings = $this->policy->evaluate(snapshot([
            'scheduledTasks' => ['payments:reconcile-settlements' => true],
        ]));

        expect(codes($findings, FindingSeverity::Warning))->toContain('FINANCIAL_SCHEDULE_ENABLED');
    });

    it('says nothing about financial jobs that are registered disabled', function (): void {
        expect(codes($this->policy->evaluate(snapshot([
            'scheduledTasks' => [
                'payments:reconcile-settlements' => false,
                'payments:settlement-report' => false,
            ],
        ]))))->toBe([]);
    });
});

it('reports every problem in one pass rather than stopping at the first', function (): void {
    // An operator fixing a misconfigured box should get the whole list, not
    // one item per deploy attempt.
    $findings = $this->policy->evaluate(snapshot([
        'appDebug' => true,
        'logLevel' => 'debug',
        'sessionSecureCookie' => false,
        'dbSslMode' => 'disable',
        'redisPassword' => '',
    ]));

    expect(codes($findings))->toContain(
        'APP_DEBUG_IN_PRODUCTION',
        'LOG_LEVEL_DEBUG',
        'SESSION_COOKIE_INSECURE',
        'DB_TLS_NOT_REQUIRED',
        'REDIS_UNAUTHENTICATED',
    );
});

it('names every environment the platform is allowed to run in', function (): void {
    expect(array_column(DeploymentEnvironment::cases(), 'value'))
        ->toBe(['local', 'testing', 'staging', 'production']);
});
