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
use EruoFood\Payments\Application\Service\PaymentService;
use EruoFood\Payments\Application\Service\WalletService;
use EruoFood\Payments\Domain\Enum\PaymentMethodType;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\RefundModel;
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
echo "\n8) Ledger integrity after all of the above\n";
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
