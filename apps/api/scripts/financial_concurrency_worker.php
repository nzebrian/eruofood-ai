<?php

declare(strict_types=1);

/**
 * Concurrency worker for financial_concurrency_validation.php.
 *
 * Each invocation is a separate OS process with its own database connection —
 * the only way to exercise row locking for real, since a single process (and
 * anything wrapped in Pest's RefreshDatabase transaction) can never observe
 * another connection's contention.
 *
 * Every worker busy-waits until a shared start timestamp before touching the
 * database, so the operations collide instead of politely queueing.
 *
 * Args: <scenario> <startAtMicros> <arg1> [arg2...]
 * Exit: 0 when the operation succeeded, 1 when it was correctly rejected,
 *       2 on an unexpected error (printed to stderr).
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use EruoFood\Commerce\Application\Input\CheckoutInput as CommerceCheckoutInput;
use EruoFood\Commerce\Application\Service\CheckoutService as CommerceCheckout;
use EruoFood\Loyalty\Application\Service\RedemptionService;
use EruoFood\Payments\Application\Service\PayableAccrualService;
use EruoFood\Payments\Application\Service\RefundService;
use EruoFood\Payments\Application\Service\SettlementReconciliationService;
use EruoFood\Payments\Application\Service\SettlementRunService;
use EruoFood\Payments\Application\Service\WalletService;
use EruoFood\Payments\Application\Service\WebhookService;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;

/**
 * Turn the settlement flags on for this worker process only.
 *
 * Set here rather than in the environment so a harness run is self-contained:
 * the flags are off by default everywhere, and a scenario that silently did
 * nothing because a flag was off would report as a pass.
 */
function settlementFlags(): void
{
    config([
        'flags.overrides.settlement.accrual' => 'true',
        'flags.overrides.settlement.accrual_posting' => 'true',
        'flags.overrides.settlement.compute' => 'true',
        'flags.overrides.settlement.execute' => 'true',
        'flags.overrides.settlement.reconcile' => 'true',
    ]);
}

$scenario = (string) ($argv[1] ?? '');
$startAt = (float) ($argv[2] ?? 0);

// Spin until the agreed instant so the workers land together.
while (microtime(true) < $startAt) {
    usleep(200);
}

try {
    switch ($scenario) {
        case 'wallet-debit':
            $wallets = app(WalletService::class);
            $wallet = $wallets->getOrOpen(WalletOwnerType::Customer, (string) $argv[3]);
            $wallets->debit($wallet, (int) $argv[4], TransactionType::Payment, null, 'concurrent debit');
            break;

        case 'wallet-transfer':
            app(WalletService::class)->transfer(
                WalletOwnerType::Customer,
                (string) $argv[3],
                WalletOwnerType::Customer,
                (string) $argv[4],
                (int) $argv[5],
                'concurrent transfer',
            );
            break;

        case 'refund':
            app(RefundService::class)->request(
                (string) $argv[3],
                (int) $argv[4],
                'concurrent refund',
                (string) $argv[5],
                true,
            );
            break;

        case 'commerce-checkout':
            app(CommerceCheckout::class)->place(
                (string) $argv[3],
                CommerceCheckoutInput::fromArray(['pickup' => true]),
            );
            break;

        case 'redeem':
            app(RedemptionService::class)->redeem((string) $argv[3], (string) $argv[4]);
            break;

        case 'webhook':
            $applied = app(WebhookService::class)->handle('mock', (string) $argv[3], '');
            // "Not applied" means another delivery won the claim — a correct
            // rejection, not a failure.
            exit($applied ? 0 : 1);

        case 'idempotent':
            $result = app(IdempotencyStore::class)->execute(
                'concurrency.probe',
                (string) $argv[3],
                (string) $argv[4],
                static function () use ($argv): array {
                    $wallets = app(WalletService::class);
                    $wallet = $wallets->getOrOpen(WalletOwnerType::Customer, (string) $argv[5]);
                    $wallets->credit($wallet, (int) $argv[6], TransactionType::Topup, null, 'idempotent credit');

                    return ['credited' => (int) $argv[6]];
                },
            );
            exit($result->replayed ? 1 : 0);

            // ---- M27 settlement -------------------------------------------------
            //
            // Every settlement scenario turns the flags on inside the worker. They
            // default off, so a harness run cannot accidentally depend on a flag
            // somebody left enabled in the environment — and equally cannot be
            // silently skipped because one is off.

        case 'settlement-compute':
            settlementFlags();
            app(SettlementRunService::class)->computeDraft(
                (string) $argv[3],          // actor
                'vendor',
                (string) $argv[4],          // merchant id
                new DateTimeImmutable((string) $argv[5]),
                new DateTimeImmutable((string) $argv[6]),
            );
            break;

        case 'settlement-execute':
            settlementFlags();
            app(SettlementRunService::class)->execute(
                (string) $argv[3],          // actor (must differ from approver)
                (string) $argv[4],          // run id
                $argv[5] === 'bank' ? new BankAccount('Vendor Ltd', '0123456789', '058') : null,
            );
            break;

        case 'settlement-retry':
            settlementFlags();
            app(SettlementRunService::class)->retry((string) $argv[3], (string) $argv[4], 'concurrent retry');
            break;

        case 'settlement-cancel':
            settlementFlags();
            app(SettlementRunService::class)->cancel((string) $argv[3], (string) $argv[4], 'concurrent cancel');
            break;

        case 'settlement-reconcile':
            settlementFlags();
            $summary = app(SettlementReconciliationService::class)->reconcilePayouts(50);
            // "Examined nothing" is a correct no-op, not a success.
            exit($summary['examined'] > 0 ? 0 : 1);

        case 'settlement-accrue':
            settlementFlags();
            $id = app(PayableAccrualService::class)->recordSettledOrder(
                new EruoFood\Payments\Contracts\SettledOrder((string) $argv[3], 'vendor', (string) $argv[4]),
            );
            exit($id === null ? 1 : 0);

        case 'settlement-refund-adjust':
            settlementFlags();
            $id = app(PayableAccrualService::class)->recordRefund(
                (string) $argv[3],          // order id
                (string) $argv[4],          // refund id
                new EruoFood\Shared\Domain\ValueObject\Money((int) $argv[5]),
            );
            exit($id === null ? 1 : 0);

        default:
            fwrite(STDERR, "unknown scenario: {$scenario}\n");
            exit(2);
    }

    exit(0);
} catch (EruoFood\Shared\Domain\Exception\DomainException $e) {
    // A domain refusal is the system working: insufficient balance, out of
    // stock, refund cap reached, duplicate in flight.
    fwrite(STDERR, $e->errorCode().': '.$e->getMessage()."\n");
    exit(1);
} catch (Throwable $e) {
    // Deadlocks and serialisation failures should have been retried internally;
    // anything reaching here is a genuine defect worth surfacing.
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(2);
}
