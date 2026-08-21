<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\Permission;
use EruoFood\Payments\Domain\Settlement\SettlementRun;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * M28 Phase 3 — the parts of the secrets and access posture that a test can hold.
 *
 * Most of this posture is enforced elsewhere and deliberately not restated:
 * gitleaks scans the full history in CI, `EnvironmentTemplateSeparationTest`
 * refuses a literal credential in a deployed template, and
 * `SettlementAuthorizationTest` covers who may move money. What is left are the
 * quieter leaks — the ones where a secret escapes not through a config file but
 * through something the platform *writes down about itself*.
 *
 * Those are worth their own file because they are invisible in review. A payout
 * attempt that stores the provider's raw response looks like good diagnostics
 * until somebody notices the response echoed the destination account number
 * back, and now it is in a database nobody classified as holding bank details.
 */

it('keeps no environment file in version control except the templates', function (): void {
    $tracked = trim((string) shell_exec('cd '.escapeshellarg(dirname(base_path(), 2)).' && git ls-files "*.env*" 2>/dev/null'));
    $files = $tracked === '' ? [] : explode("\n", $tracked);

    $offenders = array_values(array_filter(
        $files,
        static fn (string $f): bool => ! str_ends_with($f, '.env.example'),
    ));

    expect($offenders)->toBe([], 'environment files committed: '.implode(', ', $offenders))
        // And the templates are there, so the filter had something to filter.
        ->and($files)->not->toBeEmpty();
});

describe('the platform does not write secrets down about itself', function (): void {
    it('stores a digest of a provider response, never the response', function (): void {
        // The column name is the guarantee. A `raw_response` column would be a
        // standing invitation to store an account number.
        $columns = array_map(
            static fn ($c): string => $c->column_name,
            DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'payments_payout_attempts'"),
        );

        expect($columns)->toContain('raw_response_digest')
            ->and($columns)->not->toContain('raw_response')
            ->and($columns)->not->toContain('response_body');
    })->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'information_schema query is PostgreSQL-specific');

    it('bounds and flattens a provider failure reason before storing it', function (): void {
        // Provider error text has been known to echo the request back, which for
        // a transfer means an account number. Reasons are for operators, so they
        // are truncated and stripped of the newlines that would break a log line.
        $run = SettlementRun::draft(
            id: (string) Illuminate\Support\Str::uuid(),
            merchantType: 'vendor',
            merchantId: (string) Illuminate\Support\Str::uuid(),
            currency: 'NGN',
            windowStart: new DateTimeImmutable('-1 day'),
            windowEnd: new DateTimeImmutable('+1 day'),
            gross: new Money(100_000),
            commission: new Money(10_000),
            fee: new Money(0),
            idempotencyKey: null,
            settlementReference: 'STL-TEST-1',
            correlationId: (string) Illuminate\Support\Str::uuid(),
            computedBy: null,
            now: new DateTimeImmutable(),
        );

        $run->approve((string) Illuminate\Support\Str::uuid(), new DateTimeImmutable());
        $run->beginExecution((string) Illuminate\Support\Str::uuid(), new DateTimeImmutable());

        $noisy = "Declined\nAccount: 0123456789\r\n".str_repeat('x', 500);
        $run->markFailed($noisy, new DateTimeImmutable());

        $stored = (string) $run->failureReason();

        expect(mb_strlen($stored))->toBeLessThanOrEqual(255)
            ->and($stored)->not->toContain("\n")
            ->and($stored)->not->toContain("\r");
    });

    it('keeps the audit log append-only, so a privileged action cannot be erased', function (): void {
        $triggers = array_map(
            static fn ($t): string => $t->tgname,
            DB::select('SELECT tgname FROM pg_trigger WHERE NOT tgisinternal'),
        );

        expect($triggers)->toContain('admin_audit_log_append_only');
    })->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'trigger introspection is PostgreSQL-specific');
});

describe('least privilege over money', function (): void {
    it('grants no money-moving power to a read permission', function (): void {
        expect(Permission::moneyMoving())->not->toContain(Permission::FINANCE_READ)
            // Reconciliation reads and opens cases; it settles nothing.
            ->and(Permission::moneyMoving())->not->toContain(Permission::FINANCE_RECONCILE)
            ->and(Permission::moneyMoving())->toContain(Permission::FINANCE_PAYOUT);
    });

    it('gives customer support no finance permission at all', function (): void {
        // Before M27, `finance.read` guarded the whole back-office finance
        // group — including `POST admin/settlements`, which transfers money to
        // a bank account — and CustomerSupport held it. M27 took the role out
        // of finance entirely rather than narrowing what the permission
        // allowed, which is the stronger fix: there is now no finance
        // permission to escalate from.
        $held = Permission::forRole(AdminRole::CustomerSupport);

        $finance = array_values(array_filter(
            $held,
            static fn (string $p): bool => str_starts_with($p, 'finance.'),
        ));

        expect($finance)->toBe([])
            // The role holds something, so the filter had something to filter.
            ->and($held)->not->toBeEmpty();
    });

    it('gives an ordinary admin sight of the money and no power over it', function (): void {
        // The role that most plausibly drifts back: Admin runs the back office,
        // and "just let them settle" is a small-looking change.
        $held = Permission::forRole(AdminRole::Admin);

        expect($held)->toContain(Permission::FINANCE_READ);

        foreach (Permission::moneyMoving() as $dangerous) {
            expect($held)->not->toContain($dangerous, "admin holds {$dangerous}");
        }
    });

    it('grants the two actions that rewrite financial history to no ordinary role', function (): void {
        // adjust makes the books say something different from what they said;
        // reverse undoes a settlement that already paid out. SuperAdmin holds
        // every permission implicitly, so the assertion is about everyone else.
        $checked = 0;

        foreach ([Permission::FINANCE_ADJUST, Permission::FINANCE_REVERSE] as $permission) {
            foreach (AdminRole::cases() as $role) {
                if ($role->isSuper()) {
                    continue;
                }

                $checked++;
                expect(Permission::forRole($role))->not->toContain(
                    $permission,
                    sprintf('%s holds %s', $role->value, $permission),
                );
            }
        }

        // The sweep covered every non-super role for both permissions.
        expect($checked)->toBe((count(AdminRole::cases()) - 1) * 2);
    });
});
