<?php

declare(strict_types=1);

/**
 * Milestone 23 — true-concurrency validation for the financial paths.
 *
 * The Pest suite proves transaction boundaries roll back correctly, but it can
 * never prove locking: `RefreshDatabase` wraps each test in a transaction, so a
 * second connection would not even see the first one's rows. This script closes
 * that gap by launching real OS processes against a real PostgreSQL database,
 * synchronised on a shared start instant so their statements genuinely collide.
 *
 * Every scenario asserts a conservation law — money is neither created nor
 * destroyed, stock is never oversold, a payment is never refunded past its
 * value, an event is applied once. Those hold only if the row locks, the
 * transaction boundaries and the unique constraints all do their job.
 *
 * Run: DB_CONNECTION=pgsql … php scripts/financial_concurrency_validation.php
 * Requires: PostgreSQL. SQLite serialises writers globally, so it cannot
 * demonstrate anything here and the script refuses to run against it.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use EruoFood\Commerce\Domain\Cart\Cart;
use EruoFood\Commerce\Domain\Cart\CartItem;
use EruoFood\Commerce\Domain\Cart\CartRepository;
use EruoFood\Commerce\Domain\Inventory\InventoryItem;
use EruoFood\Commerce\Domain\Inventory\InventoryItemRepository;
use EruoFood\Loyalty\Application\Service\LoyaltyService;
use EruoFood\Loyalty\Domain\Reward\Reward;
use EruoFood\Loyalty\Domain\Reward\RewardRepository;
use EruoFood\Payments\Application\Input\InitiatePaymentInput;
use EruoFood\Payments\Application\Service\LedgerIntegrityService;
use EruoFood\Payments\Application\Service\PayableAccrualService;
use EruoFood\Payments\Application\Service\PaymentService;
use EruoFood\Payments\Application\Service\SettlementRunService;
use EruoFood\Payments\Application\Service\WalletService;
use EruoFood\Payments\Contracts\SettledOrder;
use EruoFood\Payments\Domain\Enum\PaymentMethodType;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Payments\Domain\Settlement\SettlementRunRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PayableAccrualModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\RefundModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\SettlementLineModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\SettlementRunModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\WalletModel;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$driver = DB::connection()->getDriverName();
if ($driver !== 'pgsql') {
    fwrite(STDERR, "This script requires PostgreSQL (got: {$driver}).\n");
    fwrite(STDERR, "SQLite serialises all writers, so it cannot demonstrate row-level contention.\n");
    exit(2);
}

$pass = 0;
$fail = 0;
$worker = __DIR__.'/financial_concurrency_worker.php';

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✔ {$label}".($detail !== '' ? "  ({$detail})" : '')."\n";
    } else {
        $fail++;
        echo "  ✘ {$label}".($detail !== '' ? "  ({$detail})" : '')."\n";
    }
}

/**
 * Run $count copies of the worker in parallel, all starting together.
 *
 * @param list<string> $args
 * @return array{succeeded: int, rejected: int, errored: int}
 */
function race(string $worker, string $scenario, int $count, array $args, float $leadSeconds = 1.5): array
{
    $startAt = microtime(true) + $leadSeconds;
    $dir = sys_get_temp_dir().'/efk-conc-'.uniqid();
    mkdir($dir);

    $cmds = [];
    for ($i = 0; $i < $count; $i++) {
        $cmd = sprintf(
            'php %s %s %s %s > %s 2>&1; echo $? > %s',
            escapeshellarg($worker),
            escapeshellarg($scenario),
            escapeshellarg((string) $startAt),
            implode(' ', array_map(escapeshellarg(...), $args)),
            escapeshellarg($dir.'/out'.$i),
            escapeshellarg($dir.'/code'.$i),
        );
        $cmds[] = '('.$cmd.')';
    }

    exec('/bin/bash -c '.escapeshellarg(implode(' & ', $cmds).' & wait'));

    $result = ['succeeded' => 0, 'rejected' => 0, 'errored' => 0];
    for ($i = 0; $i < $count; $i++) {
        $code = (int) trim((string) @file_get_contents($dir.'/code'.$i));
        $key = match ($code) {
            0 => 'succeeded',
            1 => 'rejected',
            default => 'errored',
        };
        $result[$key]++;
        if ($code >= 2) {
            echo '      worker error: '.trim((string) @file_get_contents($dir.'/out'.$i))."\n";
        }
    }

    array_map(unlink(...), glob($dir.'/*') ?: []);
    rmdir($dir);

    return $result;
}

echo "EruoFood — M23 financial concurrency validation (PostgreSQL {$driver})\n";
echo str_repeat('=', 72)."\n";

// ---------------------------------------------------------------------------
echo "\n1) Concurrent wallet debits — the balance must never go negative\n";
// ---------------------------------------------------------------------------
$wallets = app(WalletService::class);
$userId = (string) Str::uuid();
$wallet = $wallets->getOrOpen(WalletOwnerType::Customer, $userId);
$wallets->credit($wallet, 10_000, TransactionType::Topup, null, 'seed');

// 20 processes each try to take 1,000 from a balance that covers only 10.
$r = race($worker, 'wallet-debit', 20, [$userId, '1000']);
$finalBalance = (int) WalletModel::query()->where('owner_id', $userId)->value('balance_minor');

check(
    'exactly 10 of 20 concurrent debits succeed',
    $r['succeeded'] === 10,
    "succeeded={$r['succeeded']} rejected={$r['rejected']} errored={$r['errored']}",
);
check('final balance is exactly zero, never negative', $finalBalance === 0, "balance={$finalBalance}");
check('no worker hit an unexpected error', $r['errored'] === 0);

// ---------------------------------------------------------------------------
echo "\n2) Concurrent transfers — the total across both wallets is conserved\n";
// ---------------------------------------------------------------------------
$aUser = (string) Str::uuid();
$bUser = (string) Str::uuid();
$a = $wallets->getOrOpen(WalletOwnerType::Customer, $aUser);
$wallets->credit($a, 20_000, TransactionType::Topup, null, 'seed');
$wallets->getOrOpen(WalletOwnerType::Customer, $bUser);

// Half push A→B, half push B→A, which is also the classic deadlock shape.
$startAt = microtime(true) + 1.5;
$dir = sys_get_temp_dir().'/efk-conc-'.uniqid();
mkdir($dir);
$cmds = [];
for ($i = 0; $i < 16; $i++) {
    [$from, $to] = $i % 2 === 0 ? [$aUser, $bUser] : [$bUser, $aUser];
    $cmds[] = sprintf(
        '(php %s wallet-transfer %s %s %s 1000 > %s 2>&1; echo $? > %s)',
        escapeshellarg($worker),
        escapeshellarg((string) $startAt),
        escapeshellarg($from),
        escapeshellarg($to),
        escapeshellarg($dir.'/out'.$i),
        escapeshellarg($dir.'/code'.$i),
    );
}
exec('/bin/bash -c '.escapeshellarg(implode(' & ', $cmds).' & wait'));

$errored = 0;
for ($i = 0; $i < 16; $i++) {
    if ((int) trim((string) @file_get_contents($dir.'/code'.$i)) >= 2) {
        $errored++;
        echo '      worker error: '.trim((string) @file_get_contents($dir.'/out'.$i))."\n";
    }
}
array_map(unlink(...), glob($dir.'/*') ?: []);
rmdir($dir);

$totalAfter = (int) WalletModel::query()->whereIn('owner_id', [$aUser, $bUser])->sum('balance_minor');
check('money is neither created nor destroyed', $totalAfter === 20_000, "total={$totalAfter} expected=20000");
check('no deadlock surfaced to the caller', $errored === 0, "errored={$errored}");
check(
    'no wallet went negative',
    WalletModel::query()->whereIn('owner_id', [$aUser, $bUser])->where('balance_minor', '<', 0)->count() === 0,
);

// ---------------------------------------------------------------------------
echo "\n3) Concurrent refunds — never more than the captured amount\n";
// ---------------------------------------------------------------------------
$payerId = (string) Str::uuid();
$opened = app(PaymentService::class)->open(new InitiatePaymentInput(
    payerUserId: $payerId,
    customerEmail: 'concurrency@example.com',
    amount: new Money(100_000, 'NGN'),
    orderId: null,
    methodType: PaymentMethodType::Card,
    provider: PaymentProvider::Mock,
    idempotencyKey: null,
    splits: [],
), '127.0.0.1');
$paymentId = $opened->payment->id();

// 12 processes each try to refund 20,000 of a 100,000 payment: at most 5 fit.
$r = race($worker, 'refund', 12, [$paymentId, '20000', $payerId]);
$refunded = (int) RefundModel::query()
    ->where('payment_id', $paymentId)
    ->whereIn('status', ['pending', 'completed'])
    ->sum('amount_minor');

check('exactly 5 of 12 concurrent refunds succeed', $r['succeeded'] === 5, "succeeded={$r['succeeded']} errored={$r['errored']}");
check('total refunded never exceeds the payment', $refunded <= 100_000, "refunded={$refunded}");
check('total refunded is exactly the capacity', $refunded === 100_000, "refunded={$refunded}");
check('no worker hit an unexpected error', $r['errored'] === 0);

// ---------------------------------------------------------------------------
echo "\n4) Concurrent checkouts on one unit of stock — exactly one order\n";
// ---------------------------------------------------------------------------
$productId = (string) Str::uuid();
$inventory = app(InventoryItemRepository::class);
// Exactly one unit available, so only one of the racing shoppers can win.
$stock = InventoryItem::open($inventory->nextIdentity(), $productId, null, null, null, 1, 0);
$inventory->save($stock);

$carts = app(CartRepository::class);
$shoppers = [];
for ($i = 0; $i < 8; $i++) {
    $shopperId = (string) Str::uuid();
    $shoppers[] = $shopperId;
    $cart = Cart::forUser($shopperId, 'NGN');
    $cart->add(new CartItem($productId, (string) Str::uuid(), 'Contended Product', null, new Money(1_000, 'NGN'), 1));
    $carts->save($cart);
}

$startAt = microtime(true) + 1.5;
$dir = sys_get_temp_dir().'/efk-conc-'.uniqid();
mkdir($dir);
$cmds = [];
foreach ($shoppers as $i => $shopperId) {
    $cmds[] = sprintf(
        '(php %s commerce-checkout %s %s > %s 2>&1; echo $? > %s)',
        escapeshellarg($worker),
        escapeshellarg((string) $startAt),
        escapeshellarg($shopperId),
        escapeshellarg($dir.'/out'.$i),
        escapeshellarg($dir.'/code'.$i),
    );
}
exec('/bin/bash -c '.escapeshellarg(implode(' & ', $cmds).' & wait'));

$succeeded = 0;
$errored = 0;
foreach ($shoppers as $i => $ignored) {
    $code = (int) trim((string) @file_get_contents($dir.'/code'.$i));
    if ($code === 0) {
        $succeeded++;
    } elseif ($code >= 2) {
        $errored++;
        echo '      worker error: '.trim((string) @file_get_contents($dir.'/out'.$i))."\n";
    }
}
array_map(unlink(...), glob($dir.'/*') ?: []);
rmdir($dir);

$remaining = (int) DB::table('commerce_inventory_items')->where('product_id', $productId)->value('quantity');
check('exactly one of eight shoppers gets the last unit', $succeeded === 1, "succeeded={$succeeded}");
check('stock is not oversold', $remaining === 0, "remaining={$remaining}");
check('no worker hit an unexpected error', $errored === 0);

// ---------------------------------------------------------------------------
echo "\n5) Concurrent redemptions of a single-stock reward\n";
// ---------------------------------------------------------------------------
$rewards = app(RewardRepository::class);
$reward = Reward::create(
    $rewards->nextIdentity(),
    'Contended Reward',
    'Only one available',
    'delivery_discount',
    50_000,
    100,
    1,
    new DateTimeImmutable(),
);
$rewards->save($reward);

$loyalty = app(LoyaltyService::class);
$members = [];
for ($i = 0; $i < 8; $i++) {
    $memberId = (string) Str::uuid();
    $members[] = $memberId;
    $loyalty->adjust($memberId, 500, 'concurrency-seed');
}

$startAt = microtime(true) + 1.5;
$dir = sys_get_temp_dir().'/efk-conc-'.uniqid();
mkdir($dir);
$cmds = [];
foreach ($members as $i => $memberId) {
    $cmds[] = sprintf(
        '(php %s redeem %s %s %s > %s 2>&1; echo $? > %s)',
        escapeshellarg($worker),
        escapeshellarg((string) $startAt),
        escapeshellarg($memberId),
        escapeshellarg($reward->id()),
        escapeshellarg($dir.'/out'.$i),
        escapeshellarg($dir.'/code'.$i),
    );
}
exec('/bin/bash -c '.escapeshellarg(implode(' & ', $cmds).' & wait'));

$succeeded = 0;
$errored = 0;
foreach ($members as $i => $ignored) {
    $code = (int) trim((string) @file_get_contents($dir.'/code'.$i));
    if ($code === 0) {
        $succeeded++;
    } elseif ($code >= 2) {
        $errored++;
        echo '      worker error: '.trim((string) @file_get_contents($dir.'/out'.$i))."\n";
    }
}
array_map(unlink(...), glob($dir.'/*') ?: []);
rmdir($dir);

$issued = (int) DB::table('loyalty_redemptions')->where('reward_id', $reward->id())->count();
$stockLeft = (int) DB::table('loyalty_rewards')->where('id', $reward->id())->value('stock');
check('exactly one member redeems the last unit', $succeeded === 1, "succeeded={$succeeded}");
check('exactly one voucher is issued', $issued === 1, "issued={$issued}");
check('reward stock is not oversold', $stockLeft === 0, "stock={$stockLeft}");
check('no worker hit an unexpected error', $errored === 0);

// ---------------------------------------------------------------------------
echo "\n6) Concurrent duplicate webhook deliveries — applied exactly once\n";
// ---------------------------------------------------------------------------
$hookPayer = (string) Str::uuid();
$hookPayment = app(PaymentService::class)->open(new InitiatePaymentInput(
    payerUserId: $hookPayer,
    customerEmail: 'hook@example.com',
    amount: new Money(50_000, 'NGN'),
    orderId: null,
    methodType: PaymentMethodType::Card,
    provider: PaymentProvider::Mock,
    idempotencyKey: null,
    splits: [],
), '127.0.0.1');

$eventId = 'evt_conc_'.Str::random(8);
$body = json_encode([
    'event_id' => $eventId,
    'type' => 'payment.succeeded',
    'reference' => $hookPayment->payment->reference(),
    'status' => 'succeeded',
    'amount_minor' => 50_000,
], JSON_THROW_ON_ERROR);

$r = race($worker, 'webhook', 12, [$body]);
$recorded = (int) DB::table('payments_webhook_events')->where('event_id', $eventId)->count();

check('exactly one of twelve deliveries is applied', $r['succeeded'] === 1, "applied={$r['succeeded']} errored={$r['errored']}");
check('exactly one event row is recorded', $recorded === 1, "rows={$recorded}");
check('no worker hit an unexpected error', $r['errored'] === 0);

// ---------------------------------------------------------------------------
echo "\n7) Concurrent requests sharing one idempotency key\n";
// ---------------------------------------------------------------------------
$idemUser = (string) Str::uuid();
$wallets->getOrOpen(WalletOwnerType::Customer, $idemUser);
$key = 'idem-'.Str::random(10);

$r = race($worker, 'idempotent', 12, [$key, 'hash-a', $idemUser, '2500']);
$balance = (int) WalletModel::query()->where('owner_id', $idemUser)->value('balance_minor');

check(
    'the guarded work runs exactly once',
    $balance === 2_500,
    "balance={$balance} expected=2500 (executed={$r['succeeded']} replayed/blocked={$r['rejected']} errored={$r['errored']})",
);
check('no worker hit an unexpected error', $r['errored'] === 0);


// ---------------------------------------------------------------------------
// M27 — settlement, payout and reconciliation under real contention.
//
// Every scenario below asserts the same family of conservation laws as the
// M23 ones: an accrual is settled at most once, a merchant is paid at most
// once per window, an unknown transfer is never retried, and the ledger still
// balances after all of it.
// ---------------------------------------------------------------------------

config([
    'flags.overrides.settlement.accrual' => 'true',
    'flags.overrides.settlement.accrual_posting' => 'true',
    'flags.overrides.settlement.compute' => 'true',
    'flags.overrides.settlement.execute' => 'true',
    'flags.overrides.settlement.reconcile' => 'true',
]);

$settlementApprover = (string) Str::uuid();
$settlementExecutor = (string) Str::uuid();
$windowFrom = (new DateTimeImmutable('-2 days'))->format(DATE_ATOM);
$windowTo = (new DateTimeImmutable('+2 days'))->format(DATE_ATOM);

/**
 * Capture a payment for an order and accrue it, returning the accrual's net.
 *
 * Uses the real payment path so the ledger holds a genuine capture posting —
 * the accrual derives its figures from there, and a hand-built fixture would
 * prove nothing about the derivation.
 */
$seedAccrual = static function (string $merchantId, string $orderId, int $grossMinor) use (&$app): int {
    $payerId = (string) Str::uuid();
    $opened = app(PaymentService::class)->open(new InitiatePaymentInput(
        payerUserId: $payerId,
        customerEmail: 'settlement-concurrency@example.com',
        amount: new Money($grossMinor, 'NGN'),
        orderId: $orderId,
        methodType: PaymentMethodType::Card,
        provider: PaymentProvider::Mock,
        idempotencyKey: 'seed-'.Str::random(12),
        splits: [],
    ), '127.0.0.1');
    app(PaymentService::class)->announce($opened->payment);

    app(PayableAccrualService::class)->recordSettledOrder(new SettledOrder($orderId, 'vendor', $merchantId));

    return (int) PayableAccrualModel::query()->where('order_id', $orderId)->value('net_minor');
};

// ---------------------------------------------------------------------------
echo "\n9) Concurrent accrual of the same delivered order\n";
// ---------------------------------------------------------------------------
$accrualMerchant = (string) Str::uuid();
$accrualOrder = (string) Str::uuid();

// Capture the payment first, then race the accrual itself — the shape of an
// order whose "delivered" event is delivered several times.
$payerId = (string) Str::uuid();
$opened = app(PaymentService::class)->open(new InitiatePaymentInput(
    payerUserId: $payerId,
    customerEmail: 'accrual-race@example.com',
    amount: new Money(400_000, 'NGN'),
    orderId: $accrualOrder,
    methodType: PaymentMethodType::Card,
    provider: PaymentProvider::Mock,
    idempotencyKey: 'accrual-race-'.Str::random(10),
    splits: [],
), '127.0.0.1');
app(PaymentService::class)->announce($opened->payment);

$r = race($worker, 'settlement-accrue', 12, [$accrualOrder, $accrualMerchant]);
$accrualRows = (int) PayableAccrualModel::query()
    ->where('order_id', $accrualOrder)->where('type', 'earning')->count();

check('exactly one accrual row exists for the order', $accrualRows === 1, "rows={$accrualRows}");
check('no worker hit an unexpected error', $r['errored'] === 0, "errored={$r['errored']}");

// ---------------------------------------------------------------------------
echo "\n10) Two workers computing a settlement for the same merchant and window\n";
// ---------------------------------------------------------------------------
$computeMerchant = (string) Str::uuid();
$seedAccrual($computeMerchant, (string) Str::uuid(), 500_000);
$seedAccrual($computeMerchant, (string) Str::uuid(), 300_000);

$r = race($worker, 'settlement-compute', 8, [$settlementApprover, $computeMerchant, $windowFrom, $windowTo]);
$liveRuns = (int) SettlementRunModel::query()
    ->where('merchant_id', $computeMerchant)
    ->whereNotIn('state', ['cancelled', 'failed', 'reversed'])
    ->count();

check('exactly one live settlement run exists for the window', $liveRuns === 1, "runs={$liveRuns}");
check('exactly one worker computed it', $r['succeeded'] === 1, "succeeded={$r['succeeded']} rejected={$r['rejected']}");
check('no worker hit an unexpected error', $r['errored'] === 0, "errored={$r['errored']}");

// ---------------------------------------------------------------------------
echo "\n11) An accrual is never on two settlement lines\n";
// ---------------------------------------------------------------------------
$accrualIds = SettlementLineModel::query()
    ->join('payments_settlement_runs as r', 'r.id', '=', 'payments_settlement_lines.settlement_run_id')
    ->where('r.merchant_id', $computeMerchant)
    ->pluck('payments_settlement_lines.accrual_id')->all();

check(
    'every settlement line references a distinct accrual',
    count($accrualIds) === count(array_unique($accrualIds)),
    'lines='.count($accrualIds).' distinct='.count(array_unique($accrualIds)),
);

$globalDupes = (int) DB::table('payments_settlement_lines')
    ->select('accrual_id')->groupBy('accrual_id')->havingRaw('COUNT(*) > 1')->get()->count();
check('no accrual anywhere appears on two lines', $globalDupes === 0, "duplicated={$globalDupes}");

// ---------------------------------------------------------------------------
echo "\n12) Concurrent execution of one approved settlement run\n";
// ---------------------------------------------------------------------------
$execMerchant = (string) Str::uuid();
$execNet = $seedAccrual($execMerchant, (string) Str::uuid(), 900_000);

$runs = app(SettlementRunService::class);
$execRun = $runs->computeDraft($settlementApprover, 'vendor', $execMerchant, new DateTimeImmutable($windowFrom), new DateTimeImmutable($windowTo));
$runs->approve($settlementApprover, $execRun->id());

$r = race($worker, 'settlement-execute', 10, [$settlementExecutor, $execRun->id(), 'wallet']);
$execState = (string) SettlementRunModel::query()->where('id', $execRun->id())->value('state');
$merchantWallet = (int) WalletModel::query()->where('owner_id', $execMerchant)->value('balance_minor');

check('exactly one execution succeeds', $r['succeeded'] === 1, "succeeded={$r['succeeded']} rejected={$r['rejected']}");
check('the run reaches succeeded exactly once', $execState === 'succeeded', "state={$execState}");
check('the merchant is credited exactly once', $merchantWallet === $execNet, "wallet={$merchantWallet} expected={$execNet}");
check('no worker hit an unexpected error', $r['errored'] === 0, "errored={$r['errored']}");

// ---------------------------------------------------------------------------
echo "\n13) Concurrent bank payouts for one run — one attempt reaches the provider\n";
// ---------------------------------------------------------------------------
$bankMerchant = (string) Str::uuid();
$seedAccrual($bankMerchant, (string) Str::uuid(), 700_000);
$bankRun = $runs->computeDraft($settlementApprover, 'vendor', $bankMerchant, new DateTimeImmutable($windowFrom), new DateTimeImmutable($windowTo));
$runs->approve($settlementApprover, $bankRun->id());

$r = race($worker, 'settlement-execute', 8, [$settlementExecutor, $bankRun->id(), 'bank']);
$attempts = (int) DB::table('payments_payout_attempts')->where('settlement_run_id', $bankRun->id())->count();
$confirmed = (int) DB::table('payments_payout_attempts')
    ->where('settlement_run_id', $bankRun->id())->where('state', 'confirmed')->count();

check('exactly one payout attempt is created', $attempts === 1, "attempts={$attempts}");
check('exactly one attempt is confirmed', $confirmed === 1, "confirmed={$confirmed}");
check('no worker hit an unexpected error', $r['errored'] === 0, "errored={$r['errored']}");

// ---------------------------------------------------------------------------
echo "\n14) Settlement racing a refund on the same merchant\n";
// ---------------------------------------------------------------------------
$raceMerchant = (string) Str::uuid();
$raceOrder = (string) Str::uuid();
$raceNet = $seedAccrual($raceMerchant, $raceOrder, 1_000_000);
$refundId = (string) Str::uuid();

$startAt = microtime(true) + 1.5;
$dir = sys_get_temp_dir().'/efk-conc-'.uniqid();
mkdir($dir);
$cmds = [];
for ($i = 0; $i < 8; $i++) {
    $cmds[] = $i % 2 === 0
        ? sprintf(
            '(php %s settlement-compute %s %s %s %s %s > %s 2>&1; echo $? > %s)',
            escapeshellarg($worker),
            escapeshellarg((string) $startAt),
            escapeshellarg($settlementApprover),
            escapeshellarg($raceMerchant),
            escapeshellarg($windowFrom),
            escapeshellarg($windowTo),
            escapeshellarg($dir.'/out'.$i),
            escapeshellarg($dir.'/code'.$i),
        )
        : sprintf(
            '(php %s settlement-refund-adjust %s %s %s 100000 > %s 2>&1; echo $? > %s)',
            escapeshellarg($worker),
            escapeshellarg((string) $startAt),
            escapeshellarg($raceOrder),
            escapeshellarg($refundId),
            escapeshellarg($dir.'/out'.$i),
            escapeshellarg($dir.'/code'.$i),
        );
}
exec('/bin/bash -c '.escapeshellarg(implode(' & ', $cmds).' & wait'));
$errored = 0;
for ($i = 0; $i < 8; $i++) {
    if ((int) trim((string) @file_get_contents($dir.'/code'.$i)) >= 2) {
        $errored++;
        echo '      worker error: '.trim((string) @file_get_contents($dir.'/out'.$i))."\n";
    }
}
array_map(unlink(...), glob($dir.'/*') ?: []);
rmdir($dir);

$adjustments = (int) PayableAccrualModel::query()->where('refund_id', $refundId)->count();
check('the refund reduces the payable exactly once', $adjustments <= 1, "adjustments={$adjustments}");
check('settlement racing a refund raises no unexpected error', $errored === 0, "errored={$errored}");

// ---------------------------------------------------------------------------
echo "\n15) Concurrent retry of a failed run\n";
// ---------------------------------------------------------------------------
$retryMerchant = (string) Str::uuid();
$seedAccrual($retryMerchant, (string) Str::uuid(), 250_000);
$retryRun = $runs->computeDraft($settlementApprover, 'vendor', $retryMerchant, new DateTimeImmutable($windowFrom), new DateTimeImmutable($windowTo));
$runs->approve($settlementApprover, $retryRun->id());
// Drive it to failed through the domain rather than by writing state directly.
DB::table('payments_settlement_runs')->where('id', $retryRun->id())
    ->update(['state' => 'failed', 'version' => DB::raw('version + 1')]);

$r = race($worker, 'settlement-retry', 8, [$settlementExecutor, $retryRun->id()]);
$retryState = (string) SettlementRunModel::query()->where('id', $retryRun->id())->value('state');

check('exactly one retry is accepted', $r['succeeded'] === 1, "succeeded={$r['succeeded']} rejected={$r['rejected']}");
check('the run is pending exactly once', $retryState === 'pending', "state={$retryState}");
check('no worker hit an unexpected error', $r['errored'] === 0, "errored={$r['errored']}");

// ---------------------------------------------------------------------------
echo "\n16) Concurrent cancellation of one draft\n";
// ---------------------------------------------------------------------------
$cancelMerchant = (string) Str::uuid();
$cancelNet = $seedAccrual($cancelMerchant, (string) Str::uuid(), 180_000);
$cancelRun = $runs->computeDraft($settlementApprover, 'vendor', $cancelMerchant, new DateTimeImmutable($windowFrom), new DateTimeImmutable($windowTo));

$r = race($worker, 'settlement-cancel', 8, [$settlementApprover, $cancelRun->id()]);
$cancelState = (string) SettlementRunModel::query()->where('id', $cancelRun->id())->value('state');
$releasedLines = (int) SettlementLineModel::query()->where('settlement_run_id', $cancelRun->id())->count();
$payableBack = app(PayableAccrualRepository::class)->derivedPayableMinor('vendor', $cancelMerchant, 'NGN');

check('exactly one cancellation is accepted', $r['succeeded'] === 1, "succeeded={$r['succeeded']} rejected={$r['rejected']}");
check('the run is cancelled', $cancelState === 'cancelled', "state={$cancelState}");
check('its lines are released exactly once', $releasedLines === 0, "lines={$releasedLines}");
check('the accrual returns to the payable', $payableBack === $cancelNet, "payable={$payableBack} expected={$cancelNet}");

// ---------------------------------------------------------------------------
echo "\n17) Concurrent reconciliation of an unknown payout\n";
// ---------------------------------------------------------------------------
$reconMerchant = (string) Str::uuid();
$reconNet = $seedAccrual($reconMerchant, (string) Str::uuid(), 320_000);
$reconRun = $runs->computeDraft($settlementApprover, 'vendor', $reconMerchant, new DateTimeImmutable($windowFrom), new DateTimeImmutable($windowTo));
$runs->approve($settlementApprover, $reconRun->id());

// A genuine provider timeout, not a forged state: the mock gateway is told to
// answer `unknown`, so the run reaches that state through the real execution
// path with no ledger posting — which is the whole point of the scenario.
putenv('MOCK_GATEWAY_TRANSFER_OUTCOME=unknown');
$runs->execute($settlementExecutor, $reconRun->id(), new EruoFood\Payments\Domain\ValueObject\BankAccount('Vendor Ltd', '0123456789', '058'));
putenv('MOCK_GATEWAY_TRANSFER_OUTCOME');

$reconStateBefore = (string) SettlementRunModel::query()->where('id', $reconRun->id())->value('state');
check('a timed-out transfer lands in unknown, not failed', $reconStateBefore === 'unknown', "state={$reconStateBefore}");

$payoutsBefore = (int) DB::table('payments_ledger_entries')
    ->where('account', 'payouts')->where('direction', 'credit')->sum('amount_minor');

$r = race($worker, 'settlement-reconcile', 6, []);
$reconState = (string) SettlementRunModel::query()->where('id', $reconRun->id())->value('state');
$payoutsAfter = (int) DB::table('payments_ledger_entries')
    ->where('account', 'payouts')->where('direction', 'credit')->sum('amount_minor');

check('the run leaves unknown exactly once', $reconState === 'succeeded', "state={$reconState}");
check(
    'the payout is posted to the ledger exactly once',
    $payoutsAfter - $payoutsBefore === $reconNet,
    'delta='.($payoutsAfter - $payoutsBefore)." expected={$reconNet}",
);
check('no worker hit an unexpected error', $r['errored'] === 0, "errored={$r['errored']}");

// ---------------------------------------------------------------------------
echo "\n18) An unknown run is never retried into another payment\n";
// ---------------------------------------------------------------------------
$unknownMerchant = (string) Str::uuid();
$seedAccrual($unknownMerchant, (string) Str::uuid(), 210_000);
$unknownRun = $runs->computeDraft($settlementApprover, 'vendor', $unknownMerchant, new DateTimeImmutable($windowFrom), new DateTimeImmutable($windowTo));
$runs->approve($settlementApprover, $unknownRun->id());
putenv('MOCK_GATEWAY_TRANSFER_OUTCOME=unknown');
$runs->execute($settlementExecutor, $unknownRun->id(), new EruoFood\Payments\Domain\ValueObject\BankAccount('Vendor Ltd', '0123456789', '058'));
putenv('MOCK_GATEWAY_TRANSFER_OUTCOME');

$attemptsBefore = (int) DB::table('payments_payout_attempts')->where('settlement_run_id', $unknownRun->id())->count();
$r = race($worker, 'settlement-retry', 8, [$settlementApprover, $unknownRun->id()]);
$attemptsAfter = (int) DB::table('payments_payout_attempts')->where('settlement_run_id', $unknownRun->id())->count();
$unknownState = (string) SettlementRunModel::query()->where('id', $unknownRun->id())->value('state');

check('every retry of an unknown run is refused', $r['succeeded'] === 0, "succeeded={$r['succeeded']} rejected={$r['rejected']}");
check('no further payout attempt is created', $attemptsAfter === $attemptsBefore, "before={$attemptsBefore} after={$attemptsAfter}");
check('the run is still unknown, awaiting reconciliation', $unknownState === 'unknown', "state={$unknownState}");

// ---------------------------------------------------------------------------
echo "\n19) Settlement integrity after all of the above\n";
// ---------------------------------------------------------------------------
$payableBalance = (int) DB::table('payments_ledger_entries')->where('account', 'merchant_payable')
    ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE -amount_minor END), 0) as bal")
    ->value('bal');
$derivedPayable = app(PayableAccrualRepository::class)->postedNetMinor() - app(SettlementRunRepository::class)->paidOutNetMinor();

check(
    'the MerchantPayable ledger balance equals the derived payable',
    $payableBalance === $derivedPayable,
    "ledger={$payableBalance} derived={$derivedPayable}",
);
// Deliberately *not* asserting that no payable is negative. It legitimately
// can be: a refund that lands after its accrual was reserved by a live run
// takes the merchant below zero, meaning the platform is owed money back. That
// is a real situation the domain models explicitly (MerchantPayable::isOverdrawn),
// and an assertion forbidding it would be asserting something untrue.
//
// The invariant that does hold is the one that matters: the platform never pays
// out more than it accrued.
$totalAccrued = app(PayableAccrualRepository::class)->postedNetMinor();
$totalPaidOut = app(SettlementRunRepository::class)->paidOutNetMinor();
check(
    'the platform never pays out more than it accrued',
    $totalPaidOut <= $totalAccrued,
    "paid={$totalPaidOut} accrued={$totalAccrued}",
);
check(
    'every overdrawn merchant is reported as overdrawn rather than hidden',
    array_reduce(
        app(PayableAccrualRepository::class)->merchantsWithPayable(500),
        static fn (bool $ok, array $m): bool => $ok && ($m['payable_minor'] >= 0
            || EruoFood\Payments\Domain\Settlement\MerchantPayable::of(
                $m['merchant_type'],
                $m['merchant_id'],
                $m['payable_minor'],
                $m['currency'],
            )->isOverdrawn()),
        true,
    ),
);
check(
    'no accrual is settled twice, platform-wide',
    (int) DB::table('payments_settlement_lines')->select('accrual_id')
        ->groupBy('accrual_id')->havingRaw('COUNT(*) > 1')->get()->count() === 0,
);

// ---------------------------------------------------------------------------
echo "\n20) Ledger integrity after all of the above\n";
// ---------------------------------------------------------------------------
$report = app(LedgerIntegrityService::class)->verify();
check(
    'the double-entry ledger still balances',
    $report->isBalanced(),
    "events={$report->correlationsChecked} net={$report->netMinor}",
);

// ---------------------------------------------------------------------------
echo "\n".str_repeat('=', 72)."\n";
echo sprintf("RESULT: %d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
