<?php

declare(strict_types=1);

/**
 * Concurrency worker for dispatch_concurrency_validation.php.
 *
 * Each invocation is a separate OS process with its own database connection —
 * the only way to exercise row locking for real. A single process, and anything
 * wrapped in Pest's `RefreshDatabase` transaction, can never observe another
 * connection's contention: the second "concurrent" write is the same connection
 * and never contends at all.
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

use EruoFood\Dispatch\Application\Service\AssignmentService;
use EruoFood\Dispatch\Application\Service\OfferExpiryService;
use EruoFood\Dispatch\Application\Service\ReassignmentService;
use EruoFood\Dispatch\Application\Service\VehicleService;
use EruoFood\Dispatch\Domain\Exception\AssignmentConflict;
use EruoFood\Dispatch\Domain\Exception\DispatchInvalidState;
use EruoFood\Dispatch\Domain\Exception\OfferNoLongerAnswerable;
use EruoFood\Dispatch\Domain\Exception\VehicleNotDispatchable;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use EruoFood\Shared\Domain\Exception\IdempotencyConflict;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$scenario = (string) ($argv[1] ?? '');
$startAt = (float) ($argv[2] ?? 0);

// Spin until the agreed instant so the workers land together.
while (microtime(true) < $startAt) {
    usleep(200);
}

try {
    switch ($scenario) {
        // Two riders, two offers, one delivery. At most one may end up
        // assigned — this is the race the whole context is built around.
        case 'accept':
            app(AssignmentService::class)->accept((string) $argv[3], (string) $argv[4]);
            break;

            // The same rider tapping Accept several times at once. Idempotency
            // must collapse these to one assignment rather than conflicting.
        case 'accept-idempotent':
            app(AssignmentService::class)->accept((string) $argv[3], (string) $argv[4]);
            break;

            // The rider's Accept against the expiry sweep. Exactly one wins.
        case 'expire-sweep':
            $expired = app(OfferExpiryService::class)->sweepOffers();
            exit($expired > 0 ? 0 : 1);

            // A second acceptance path that bypasses the service entirely — the
            // shape of a future refactor that forgets the lock. Only the partial
            // unique index stands between this and a rider holding two deliveries.
        case 'raw-assign':
            DB::table('dispatch_assignments')->insert([
                'id' => (string) Str::uuid(),
                'request_id' => (string) $argv[3],
                'offer_id' => (string) Str::uuid(),
                'delivery_id' => (string) $argv[4],
                'rider_id' => (string) $argv[5],
                'state' => 'accepted',
                'accepted_at' => now(),
                'updated_at' => now(),
                'version' => 1,
            ]);
            break;

            // Two operators approving the same vehicle. The loser must be told,
            // not silently overwritten.
        case 'approve-vehicle':
            app(VehicleService::class)->approve((string) $argv[3], (string) $argv[4]);
            break;

            // Two processes reassigning the same dropped-out rider. Only one may
            // open a replacement search, or the delivery gets two live ones.
        case 'reassign':
            app(ReassignmentService::class)->reassign((string) $argv[3], 'concurrent reassignment');
            break;

        default:
            fwrite(STDERR, "Unknown scenario: {$scenario}\n");
            exit(2);
    }

    exit(0);
} catch (
    AssignmentConflict
    | OfferNoLongerAnswerable
    | ConcurrencyConflict
    | IdempotencyConflict
    | DispatchInvalidState
    | VehicleNotDispatchable
    | UniqueConstraintViolationException
) {
    // A correct rejection. The whole point of the exercise is that these are
    // the *expected* outcome for every loser of a race, and that they are
    // domain refusals rather than crashes.
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(2);
}
