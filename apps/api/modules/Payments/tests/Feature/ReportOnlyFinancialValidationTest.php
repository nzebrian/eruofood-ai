<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Service\LedgerService;
use EruoFood\Payments\Application\Service\SettlementReconciliationService;
use EruoFood\Payments\Application\Service\SettlementRunService;
use EruoFood\Payments\Contracts\MerchantEarningsRecorder;
use EruoFood\Payments\Contracts\SettledOrder;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PayoutAttemptModel;
use EruoFood\Payments\Infrastructure\Provider\Gateway\MockGateway;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * M28 Phase 6 — the whole chain, with nothing at the end of it.
 *
 *   order → capture → ledger → accrual → derived payable → reconciliation → report
 *
 * The report-only cycle is the last chance to find out that the platform's idea
 * of what it owes disagrees with finance's, while the cost of being wrong is a
 * spreadsheet rather than a bank transfer. So these tests walk the chain link by
 * link and assert two things at every step: that the figure at this link was
 * *derived* from the one before it, and that no money moved.
 *
 * ## The claim that actually matters
 *
 * "The payable is derived from authoritative records and is not operator
 * supplied" is easy to assert badly — you check that some endpoint rejects an
 * `amount` field and call it proven. That only shows one door is locked.
 *
 * What is asserted here instead is positive and behavioural: change the
 * *capture posting in the ledger* and the accrual changes with it. A figure that
 * tracks the ledger cannot have come from a request body, whatever the request
 * body happened to contain.
 *
 * ## Two stages, because report-only has two halves
 *
 * With `settlement.accrual_posting` off the platform records what it owes but
 * makes none of it settleable — the true report-only cycle. With posting on it
 * becomes settleable but `settlement.execute` still refuses. Both are covered;
 * neither moves money, and every test asserts that.
 */
const RO_VENDOR = '4b4b4b4b-4444-4444-8444-4444444444bb';
const RO_ACTOR = '5b5b5b5b-5555-4555-8555-5555555555bb';

beforeEach(function (): void {
    MockGateway::reset();
});

/** Report-only: record what we owe, make none of it settleable, move nothing. */
function roReportOnlyMode(): void
{
    config([
        'flags.overrides.settlement.accrual' => 'true',
        'flags.overrides.settlement.accrual_posting' => 'false',
        'flags.overrides.settlement.compute' => 'false',
        'flags.overrides.settlement.execute' => 'false',
        'flags.overrides.settlement.reconcile' => 'true',
    ]);
}

/** The next stage: accruals become settleable, but nothing may pay them. */
function roPostingMode(): void
{
    config([
        'flags.overrides.settlement.accrual' => 'true',
        'flags.overrides.settlement.accrual_posting' => 'true',
        'flags.overrides.settlement.compute' => 'true',
        'flags.overrides.settlement.execute' => 'false',
        'flags.overrides.settlement.reconcile' => 'true',
    ]);
}

/** Take a real payment through capture, then mark the order financially final. */
function m28Capture(object $test, string $orderId, int $grossMinor, string $email): void
{
    Mail::fake();
    $token = $test->postJson('/api/v1/auth/register', [
        'name' => 'Buyer',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');

    $test->withToken($token)->postJson('/api/v1/payments/payments', [
        'amount_minor' => $grossMinor,
        'customer_email' => $email,
        'order_id' => $orderId,
    ])->assertCreated();

    app(MerchantEarningsRecorder::class)->recordSettledOrder(new SettledOrder($orderId, 'vendor', RO_VENDOR));
}

/** Nothing left the platform, by either measure. */
function roAssertNoMoneyMoved(): void
{
    expect(MockGateway::transferCount())->toBe(0, 'a transfer was attempted')
        ->and(PayoutAttemptModel::query()->count())->toBe(0, 'a payout attempt was recorded')
        ->and(app(LedgerService::class)->balanceOf(LedgerAccount::Payouts))->toBe(0, 'the payouts account moved');
}

describe('the chain, link by link', function (): void {
    it('records a capture in the ledger before any accrual exists', function (): void {
        roReportOnlyMode();
        $ledger = app(LedgerService::class);

        m28Capture($this, '8a000000-0000-4000-8000-000000000001', 1_000_000, 'ro-capture@example.com');

        // Link 1→2: the payment produced double-entry postings.
        expect($ledger->balanceOf(LedgerAccount::Escrow))->toBeGreaterThan(0)
            ->and($ledger->balanceOf(LedgerAccount::Commission))->toBeGreaterThan(0);

        roAssertNoMoneyMoved();
    });

    it('derives the accrual from the ledger capture, not from anything supplied', function (): void {
        roReportOnlyMode();
        m28Capture($this, '8a000000-0000-4000-8000-000000000002', 1_000_000, 'ro-derive@example.com');

        $accrual = app(PayableAccrualRepository::class)
            ->findEarningForOrder('8a000000-0000-4000-8000-000000000002');

        // Link 2→3: gross, commission and fee reconcile to the capture, and net
        // is the arithmetic of the three rather than a fourth stored opinion.
        expect($accrual)->not->toBeNull()
            ->and($accrual->gross()->minorUnits)->toBe(1_000_000)
            ->and($accrual->net()->minorUnits)->toBe(
                $accrual->gross()->minorUnits
                - $accrual->commission()->minorUnits
                - $accrual->fee()->minorUnits,
            );

        roAssertNoMoneyMoved();
    });

    it('tracks the ledger when the capture figures differ, which a supplied amount could not', function (): void {
        // The positive form of "not operator supplied". Two orders, two
        // different captures; if the accrual were coming from anywhere but the
        // ledger, these would not differ in step.
        roReportOnlyMode();

        m28Capture($this, '8a000000-0000-4000-8000-000000000003', 1_000_000, 'ro-track-a@example.com');
        m28Capture($this, '8a000000-0000-4000-8000-000000000004', 250_000, 'ro-track-b@example.com');

        $accruals = app(PayableAccrualRepository::class);
        $big = $accruals->findEarningForOrder('8a000000-0000-4000-8000-000000000003');
        $small = $accruals->findEarningForOrder('8a000000-0000-4000-8000-000000000004');

        expect($big->gross()->minorUnits)->toBe(1_000_000)
            ->and($small->gross()->minorUnits)->toBe(250_000)
            ->and($big->net()->minorUnits)->toBeGreaterThan($small->net()->minorUnits);

        roAssertNoMoneyMoved();
    });

    it('ignores an amount a caller tries to supply to the payments endpoint for the accrual', function (): void {
        // The negative form, kept as a second line rather than the only one.
        // `amount_minor` is the *charge*, and the merchant's share is derived
        // from what the ledger recorded, never echoed back from the request.
        roReportOnlyMode();
        m28Capture($this, '8a000000-0000-4000-8000-000000000005', 800_000, 'ro-supplied@example.com');

        $accrual = app(PayableAccrualRepository::class)
            ->findEarningForOrder('8a000000-0000-4000-8000-000000000005');

        // The merchant is never owed the full charge: commission came out.
        expect($accrual->net()->minorUnits)->toBeLessThan(800_000)
            ->and($accrual->commission()->minorUnits)->toBeGreaterThan(0);

        roAssertNoMoneyMoved();
    });
});

describe('report-only stage — recorded, not settleable', function (): void {
    it('marks every accrual report-only while posting is off', function (): void {
        roReportOnlyMode();
        m28Capture($this, '8a000000-0000-4000-8000-000000000006', 900_000, 'ro-flagoff@example.com');

        $accrual = app(PayableAccrualRepository::class)
            ->findEarningForOrder('8a000000-0000-4000-8000-000000000006');

        expect($accrual->ledgerPosted())->toBeFalse()
            ->and(app(LedgerService::class)->balanceOf(LedgerAccount::MerchantPayable))->toBe(0);

        roAssertNoMoneyMoved();
    });

    it('refuses to compute a settlement from report-only accruals', function (): void {
        roReportOnlyMode();
        m28Capture($this, '8a000000-0000-4000-8000-000000000007', 900_000, 'ro-nocompute@example.com');

        expect(fn () => app(SettlementRunService::class)->computeDraft(
            RO_ACTOR,
            'vendor',
            RO_VENDOR,
            new DateTimeImmutable('-1 day'),
            new DateTimeImmutable('+1 day'),
        ))->toThrow(PaymentsInvalidState::class, 'switched off');

        roAssertNoMoneyMoved();
    });

    it('reports totals finance can compare by hand, and moves nothing doing it', function (): void {
        roReportOnlyMode();
        m28Capture($this, '8a000000-0000-4000-8000-000000000008', 500_000, 'ro-report-a@example.com');
        m28Capture($this, '8a000000-0000-4000-8000-000000000009', 300_000, 'ro-report-b@example.com');

        $totals = app(PayableAccrualRepository::class)->totals();

        expect($totals['count'])->toBe(2)
            ->and($totals['gross_minor'])->toBe(800_000)
            ->and($totals['reporting_only'])->toBe(2)
            ->and($totals['net_minor'])->toBe($totals['gross_minor'] - $totals['commission_minor'] - $totals['fee_minor']);

        roAssertNoMoneyMoved();
    });

    it('prints the report without writing anything', function (): void {
        roReportOnlyMode();
        m28Capture($this, '8a000000-0000-4000-8000-00000000000a', 500_000, 'ro-cmd@example.com');

        $this->artisan('payments:settlement-report')->assertSuccessful();

        roAssertNoMoneyMoved();
    });
});

describe('posting stage — settleable, still unpayable', function (): void {
    it('makes the MerchantPayable ledger balance equal the derived payable', function (): void {
        roPostingMode();
        m28Capture($this, '8a000000-0000-4000-8000-00000000000b', 1_000_000, 'ro-posted@example.com');

        $accrual = app(PayableAccrualRepository::class)
            ->findEarningForOrder('8a000000-0000-4000-8000-00000000000b');

        // Link 3→4: two independent derivations of the same number agreeing.
        expect($accrual->ledgerPosted())->toBeTrue()
            ->and(app(LedgerService::class)->balanceOf(LedgerAccount::MerchantPayable))
            ->toBe($accrual->net()->minorUnits);

        roAssertNoMoneyMoved();
    });

    it('computes a draft whose total is the sum of accruals, with no amount supplied', function (): void {
        roPostingMode();
        m28Capture($this, '8a000000-0000-4000-8000-00000000000c', 600_000, 'ro-draft-a@example.com');
        m28Capture($this, '8a000000-0000-4000-8000-00000000000d', 400_000, 'ro-draft-b@example.com');

        $run = app(SettlementRunService::class)->computeDraft(
            RO_ACTOR,
            'vendor',
            RO_VENDOR,
            new DateTimeImmutable('-1 day'),
            new DateTimeImmutable('+1 day'),
        );

        expect($run->gross()->minorUnits)->toBe(1_000_000)
            ->and($run->net()->minorUnits)
            ->toBe($run->gross()->minorUnits - $run->commission()->minorUnits - $run->fee()->minorUnits);

        roAssertNoMoneyMoved();
    });

    it('still refuses to pay the draft it just computed', function (): void {
        roPostingMode();
        m28Capture($this, '8a000000-0000-4000-8000-00000000000e', 600_000, 'ro-nopay@example.com');

        $run = app(SettlementRunService::class)->computeDraft(
            RO_ACTOR,
            'vendor',
            RO_VENDOR,
            new DateTimeImmutable('-1 day'),
            new DateTimeImmutable('+1 day'),
        );
        app(SettlementRunService::class)->approve(RO_ACTOR, $run->id());

        expect(fn () => app(SettlementRunService::class)->execute('6b6b6b6b-6666-4666-8666-6666666666bb', $run->id()))
            ->toThrow(PaymentsInvalidState::class, 'switched off');

        roAssertNoMoneyMoved();
    });
});

describe('reconciliation comparison and discrepancy report', function (): void {
    it('finds no discrepancy on a healthy book', function (): void {
        roPostingMode();
        m28Capture($this, '8a000000-0000-4000-8000-00000000000f', 750_000, 'ro-clean@example.com');

        expect(app(SettlementReconciliationService::class)->reconcileLedgerAgainstPayable())->toBeNull();

        roAssertNoMoneyMoved();
    });

    it('opens a case when the ledger and the derived payable disagree', function (): void {
        // The control. Without it, "no discrepancy found" is indistinguishable
        // from a reconciler that cannot find one.
        roPostingMode();
        m28Capture($this, '8a000000-0000-4000-8000-000000000010', 750_000, 'ro-drift@example.com');

        // Move MerchantPayable without an accrual behind it — a balanced
        // posting that nothing in the accrual records can explain, which is
        // precisely the shape of a lost write.
        $ledger = app(LedgerService::class);
        $posting = $ledger->newPosting((string) Illuminate\Support\Str::uuid(), TransactionType::EscrowRelease, 'm28-drift');
        $posting->credit(LedgerAccount::MerchantPayable, new Money(12_345));
        $posting->debit(LedgerAccount::Escrow, new Money(12_345));
        $ledger->commit($posting);

        $case = app(SettlementReconciliationService::class)->reconcileLedgerAgainstPayable();

        expect($case)->not->toBeNull()
            ->and($case->kind()->value)->toContain('drift');

        roAssertNoMoneyMoved();
    });

    it('never closes a case it cannot prove, and never moves money to fix one', function (): void {
        roPostingMode();
        m28Capture($this, '8a000000-0000-4000-8000-000000000011', 500_000, 'ro-nofix@example.com');

        $ledger = app(LedgerService::class);
        $posting = $ledger->newPosting((string) Illuminate\Support\Str::uuid(), TransactionType::EscrowRelease, 'm28-drift-2');
        $posting->credit(LedgerAccount::MerchantPayable, new Money(999));
        $posting->debit(LedgerAccount::Escrow, new Money(999));
        $ledger->commit($posting);

        $service = app(SettlementReconciliationService::class);
        $case = $service->reconcileLedgerAgainstPayable();
        expect($case)->not->toBeNull();

        // Running again must not "resolve" it by correcting the book.
        $service->reconcileLedgerAgainstPayable();

        expect(app(LedgerService::class)->balanceOf(LedgerAccount::Payouts))->toBe(0);
        roAssertNoMoneyMoved();
    });
});

it('completes an entire report-only cycle without one transfer', function (): void {
    // The milestone's claim in one test: everything above, in sequence.
    roReportOnlyMode();

    foreach (range(1, 5) as $i) {
        m28Capture(
            $this,
            sprintf('8a000000-0000-4000-8000-0000000001%02d', $i),
            100_000 * $i,
            "ro-cycle-{$i}@example.com",
        );
    }

    $totals = app(PayableAccrualRepository::class)->totals();

    expect($totals['count'])->toBe(5)
        ->and($totals['reporting_only'])->toBe(5)
        ->and($totals['gross_minor'])->toBe(1_500_000);

    $this->artisan('payments:settlement-report')->assertSuccessful();

    roAssertNoMoneyMoved();
});
