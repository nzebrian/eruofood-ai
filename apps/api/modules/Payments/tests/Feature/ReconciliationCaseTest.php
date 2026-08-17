<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Service\ReconciliationCaseService;
use EruoFood\Payments\Domain\Enum\DiscrepancyKind;
use EruoFood\Payments\Domain\Enum\ReconciliationState;
use EruoFood\Payments\Domain\Enum\SettlementRunState;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Settlement\ReconciliationCase;
use EruoFood\Payments\Domain\Settlement\ReconciliationCaseRepository;
use EruoFood\Payments\Domain\Settlement\SettlementRun;
use EruoFood\Payments\Domain\Settlement\SettlementRunRepository;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function openCase(DiscrepancyKind $kind = DiscrepancyKind::PayableDrift, ?string $subjectId = null): ReconciliationCase
{
    return ReconciliationCase::open(
        id: (string) Str::orderedUuid(),
        kind: $kind,
        subjectType: 'platform',
        subjectId: $subjectId ?? (string) Str::orderedUuid(),
        expected: new Money(1000),
        observed: new Money(400),
        detail: 'test discrepancy',
        correlationId: 'test',
        now: new DateTimeImmutable(),
    );
}

it('refuses to close a case as adjusted without a compensating posting', function (): void {
    // The rule that makes "never silently corrected" true rather than aspirational.
    $case = openCase();
    $case->beginInvestigation(new DateTimeImmutable());

    expect(fn () => $case->resolveAdjusted('actor-1', '', 'we fixed it', new DateTimeImmutable()))
        ->toThrow(PaymentsInvalidState::class, 'compensating ledger posting');
});

it('refuses to close a case as adjusted without a named approver', function (): void {
    $case = openCase();
    $case->beginInvestigation(new DateTimeImmutable());

    expect(fn () => $case->resolveAdjusted('  ', 'posting-1', 'we fixed it', new DateTimeImmutable()))
        ->toThrow(PaymentsInvalidState::class, 'named approver');
});

it('refuses to close a case as adjusted without a reason', function (): void {
    $case = openCase();
    $case->beginInvestigation(new DateTimeImmutable());

    expect(fn () => $case->resolveAdjusted('actor-1', 'posting-1', '   ', new DateTimeImmutable()))
        ->toThrow(PaymentsInvalidState::class, 'needs a reason');
});

it('closes a case as adjusted when both approver and posting are present', function (): void {
    $case = openCase();
    $case->beginInvestigation(new DateTimeImmutable());
    $case->resolveAdjusted('actor-1', 'posting-1', 'ledger corrected', new DateTimeImmutable());

    expect($case->state())->toBe(ReconciliationState::ResolvedAdjusted)
        ->and($case->resolvedBy())->toBe('actor-1')
        ->and($case->compensatingPostingId())->toBe('posting-1');
});

it('refuses to auto-close a drift between two numbers the platform itself wrote', function (): void {
    // Only a provider mismatch can honestly resolve itself by being asked
    // again. A payable drift asked twice gives the same answer.
    $drift = openCase(DiscrepancyKind::PayableDrift);

    expect(fn () => $drift->resolveMatched(null, 'looks fine now', new DateTimeImmutable()))
        ->toThrow(PaymentsInvalidState::class, 'cannot be closed automatically');
});

it('allows a provider mismatch to auto-close once the provider agrees', function (): void {
    $mismatch = openCase(DiscrepancyKind::PayoutStateMismatch);
    $mismatch->resolveMatched(null, 'provider confirmed on retry', new DateTimeImmutable());

    expect($mismatch->state())->toBe(ReconciliationState::ResolvedMatched)
        ->and($mismatch->resolvedBy())->toBeNull();
});

it('marks exactly one discrepancy kind auto-resolvable', function (): void {
    $auto = array_values(array_filter(
        DiscrepancyKind::cases(),
        static fn (DiscrepancyKind $k): bool => $k->isAutoResolvable(),
    ));

    expect($auto)->toBe([DiscrepancyKind::PayoutStateMismatch]);
});

it('refuses a resolution that names a ledger posting which does not exist', function (): void {
    // A resolution whose evidence is invented is worse than an open case,
    // because it looks settled.
    $case = openCase(DiscrepancyKind::PayoutStateMismatch);
    $stored = app(ReconciliationCaseRepository::class)->openOrReturnExisting($case);

    expect(fn () => app(ReconciliationCaseService::class)
        ->resolveAdjusted('actor-1', $stored->id(), (string) Str::orderedUuid(), 'made up'))
        ->toThrow(PaymentsInvalidState::class, 'No ledger posting exists');
});

it('opens one case per subject and returns the existing one after that', function (): void {
    $subject = (string) Str::orderedUuid();
    $repo = app(ReconciliationCaseRepository::class);

    $first = $repo->openOrReturnExisting(openCase(DiscrepancyKind::PayableDrift, $subject));
    $second = $repo->openOrReturnExisting(openCase(DiscrepancyKind::PayableDrift, $subject));

    expect($second->id())->toBe($first->id())
        ->and($repo->unresolvedCount())->toBe(1);
});

it('refuses a case comparing two currencies', function (): void {
    expect(fn () => ReconciliationCase::open(
        id: 'c1',
        kind: DiscrepancyKind::PayableDrift,
        subjectType: 'platform',
        subjectId: 's1',
        expected: new Money(100, 'NGN'),
        observed: new Money(100, 'USD'),
        detail: null,
        correlationId: 'x',
        now: new DateTimeImmutable(),
    ))->toThrow(PaymentsInvalidState::class, 'two currencies');
});

it('refuses a case with no subject, which could never be deduplicated', function (): void {
    expect(fn () => ReconciliationCase::open(
        id: 'c1',
        kind: DiscrepancyKind::PayableDrift,
        subjectType: 'platform',
        subjectId: '  ',
        expected: new Money(100),
        observed: new Money(50),
        detail: null,
        correlationId: 'x',
        now: new DateTimeImmutable(),
    ))->toThrow(PaymentsInvalidState::class, 'needs a subject');
});

// ---------------------------------------------------------------------------
// SettlementRun domain invariants that no other suite covers directly.
// ---------------------------------------------------------------------------

function draftRun(Money $gross, Money $commission, Money $fee, string $currency = 'NGN'): SettlementRun
{
    return SettlementRun::draft(
        id: (string) Str::orderedUuid(),
        merchantType: 'vendor',
        merchantId: (string) Str::orderedUuid(),
        currency: $currency,
        windowStart: new DateTimeImmutable('-1 day'),
        windowEnd: new DateTimeImmutable('+1 day'),
        gross: $gross,
        commission: $commission,
        fee: $fee,
        idempotencyKey: null,
        settlementReference: 'STL-TEST-1',
        correlationId: 'test',
        computedBy: 'actor-1',
        now: new DateTimeImmutable(),
    );
}

it('refuses a settlement run that would pay out nothing', function (): void {
    // A zero run creates a payout for nothing and burns the window's unique
    // index, blocking the real settlement behind it.
    expect(fn () => draftRun(new Money(1000), new Money(1000), new Money(0)))
        ->toThrow(PaymentsInvalidState::class, 'positive amount');
});

it('refuses a settlement run whose deductions exceed its gross', function (): void {
    expect(fn () => draftRun(new Money(1000), new Money(900), new Money(500)))
        ->toThrow(PaymentsInvalidState::class, 'positive amount');
});

it('refuses a settlement run that mixes currencies', function (): void {
    expect(fn () => draftRun(new Money(1000, 'NGN'), new Money(100, 'USD'), new Money(0, 'NGN')))
        ->toThrow(PaymentsInvalidState::class, 'mix currencies');
});

it('refuses a settlement window that ends before it starts', function (): void {
    expect(fn () => SettlementRun::draft(
        id: 'r1',
        merchantType: 'vendor',
        merchantId: 'm1',
        currency: 'NGN',
        windowStart: new DateTimeImmutable('+1 day'),
        windowEnd: new DateTimeImmutable('-1 day'),
        gross: new Money(1000),
        commission: new Money(0),
        fee: new Money(0),
        idempotencyKey: null,
        settlementReference: 'STL-X',
        correlationId: 'c',
        computedBy: null,
        now: new DateTimeImmutable(),
    ))->toThrow(PaymentsInvalidState::class, 'must end after it starts');
});

it('refuses to release the accruals of a run that is still live', function (): void {
    // Releasing a live run's lines would hand its orders to another run while
    // this one is still trying to pay them.
    $run = draftRun(new Money(1000), new Money(100), new Money(0));

    expect(fn () => app(SettlementRunRepository::class)->releaseLines($run))
        ->toThrow(PaymentsInvalidState::class, 'does not release its accruals');
});

it('releases the accruals of a run that has been abandoned', function (): void {
    $run = draftRun(new Money(1000), new Money(100), new Money(0));
    $run->cancel(new DateTimeImmutable());

    expect($run->state())->toBe(SettlementRunState::Cancelled)
        ->and($run->state()->releasesAccruals())->toBeTrue();

    // No exception: a cancelled run may release. Asserted through a returned
    // value rather than `throwsNoExceptions()`, which runs no assertions of its
    // own and reports as risky.
    app(SettlementRunRepository::class)->releaseLines($run);

    expect(app(SettlementRunRepository::class)->linesFor($run->id()))->toBe([]);
});

it('names every state that releases accruals, and no other', function (): void {
    $releasing = array_values(array_map(
        static fn (SettlementRunState $s): string => $s->value,
        array_filter(SettlementRunState::cases(), static fn (SettlementRunState $s): bool => $s->releasesAccruals()),
    ));

    // Succeeded and unknown are absent on purpose: one has paid, and the other
    // may have.
    expect($releasing)->toBe(['failed', 'cancelled', 'reversed']);
});
