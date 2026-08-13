<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Service\LedgerIntegrityService;
use EruoFood\Payments\Application\Service\LedgerService;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\LedgerEntryModel;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M23 — the ledger must be provably balanced and provably append-only.
 *
 * The domain already refuses to write an unbalanced posting, so the
 * reconciliation check can only fail if something bypassed it. That is exactly
 * what makes it worth running: it is the alarm, not the lock.
 */
it('reports an empty ledger as balanced', function (): void {
    expect(app(LedgerIntegrityService::class)->verify()->isBalanced())->toBeTrue();
});

it('stays balanced across capture, refund and settlement postings', function (): void {
    $ledger = app(LedgerService::class);

    $ledger->recordCapture(
        (string) Str::uuid(),
        'PMT-1',
        new Money(100_000, 'NGN'),
        new Money(10_000, 'NGN'),
        new Money(0, 'NGN'),
        new Money(90_000, 'NGN'),
    );
    $ledger->recordRefund((string) Str::uuid(), 'RFD-1', new Money(25_000, 'NGN'));
    $ledger->recordSettlement((string) Str::uuid(), 'STL-1', new Money(65_000, 'NGN'));

    $report = app(LedgerIntegrityService::class)->verify();

    expect($report->isBalanced())->toBeTrue()
        ->and($report->netMinor)->toBe(0)
        ->and($report->correlationsChecked)->toBe(3)
        ->and($report->unbalancedCorrelationIds)->toBe([]);
});

it('detects a correlation whose entries do not net to zero', function (): void {
    $correlationId = (string) Str::uuid();

    // Write a lone debit straight to storage — the shape a partially-committed
    // posting or a manual correction would leave behind.
    LedgerEntryModel::query()->insert([
        'id' => (string) Str::orderedUuid(),
        'correlation_id' => $correlationId,
        'account' => LedgerAccount::Cash->value,
        'direction' => 'debit',
        'amount_minor' => 5_000,
        'currency' => 'NGN',
        'type' => TransactionType::Payment->value,
        'reference' => 'ORPHAN',
        'posted_at' => now(),
    ]);

    $report = app(LedgerIntegrityService::class)->verify();

    expect($report->isBalanced())->toBeFalse()
        ->and($report->netMinor)->toBe(-5_000)
        ->and($report->unbalancedCorrelationIds)->toBe([$correlationId]);
});

it('refuses to post an unbalanced group in the first place', function (): void {
    $ledger = app(LedgerService::class);
    $posting = $ledger->newPosting((string) Str::uuid(), TransactionType::Payment, 'BAD')
        ->debit(LedgerAccount::Cash, new Money(1_000, 'NGN'))
        ->credit(LedgerAccount::Escrow, new Money(400, 'NGN'));

    expect(fn () => $ledger->commit($posting))
        ->toThrow(EruoFood\Payments\Domain\Exception\PaymentsInvalidState::class);

    expect(LedgerEntryModel::query()->count())->toBe(0);
});

it('rejects any attempt to update or delete a posted entry', function (): void {
    // The append-only guarantee is a database trigger, which PostgreSQL enforces
    // and SQLite has no equivalent for. Skipping keeps the SQLite suite honest
    // rather than asserting a protection that is not there.
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The append-only trigger is a PostgreSQL object; verified on the production engine.');
    }

    app(LedgerService::class)->recordCapture(
        (string) Str::uuid(),
        'PMT-IMMUTABLE',
        new Money(10_000, 'NGN'),
        new Money(1_000, 'NGN'),
        new Money(0, 'NGN'),
        new Money(9_000, 'NGN'),
    );

    $id = LedgerEntryModel::query()->value('id');
    $before = (int) LedgerEntryModel::query()->whereKey($id)->value('amount_minor');

    // Each rejected statement gets its own savepoint. The trigger raises, which
    // aborts the enclosing PostgreSQL transaction; without the savepoint the
    // first assertion would poison everything after it.
    expect(fn () => DB::transaction(
        fn () => DB::table('payments_ledger_entries')->where('id', $id)->update(['amount_minor' => 1]),
    ))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(
        fn () => DB::table('payments_ledger_entries')->where('id', $id)->delete(),
    ))->toThrow(QueryException::class);

    // The entry is untouched and the book still balances.
    expect((int) LedgerEntryModel::query()->whereKey($id)->value('amount_minor'))->toBe($before)
        ->and(app(LedgerIntegrityService::class)->verify()->isBalanced())->toBeTrue();
});
