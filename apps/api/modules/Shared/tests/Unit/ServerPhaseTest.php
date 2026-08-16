<?php

declare(strict_types=1);

use EruoFood\Commerce\Domain\Enum\OrderStatus as CommerceOrderStatus;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Dispatch\Domain\Enum\DispatchState;
use EruoFood\Dispatch\Domain\Enum\OfferState;
use EruoFood\Marketplace\Domain\Enum\DeliveryStatus;
use EruoFood\Marketplace\Domain\Enum\OrderStatus as MarketplaceOrderStatus;
use EruoFood\Payments\Domain\Enum\PaymentStatus;
use EruoFood\Payments\Domain\Enum\PayoutStatus;
use EruoFood\Payments\Domain\Enum\RefundStatus;
use EruoFood\Payments\Domain\Enum\SettlementStatus;
use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/** Every enum that projects onto the platform-wide phase vocabulary. */
$projected = [
    PaymentStatus::class,
    RefundStatus::class,
    SettlementStatus::class,
    PayoutStatus::class,
    CommerceOrderStatus::class,
    MarketplaceOrderStatus::class,
    DeliveryStatus::class,
    AssignmentState::class,
    OfferState::class,
    DispatchState::class,
];

// ------------------------------------------- the property that prevents harm

it('never reports an in-flight money-moving state as successful', function (string $enum): void {
    // The failure this whole vocabulary exists to prevent: an app deciding a
    // payment worked because the request was accepted.
    foreach ($enum::cases() as $case) {
        if ($case->serverPhase()->isInFlight()) {
            expect($case->serverPhase()->isSuccessful())
                ->toBeFalse("{$enum}::{$case->name} is in flight and must not read as successful.");
        }
    }
})->with([PaymentStatus::class, RefundStatus::class, SettlementStatus::class, PayoutStatus::class]);

it('refuses to call a processing payment safely retryable', function (): void {
    // A payment initialised at the provider may still succeed. Retrying it is
    // how a customer gets charged twice, so the phase must say so.
    expect(PaymentStatus::Processing->serverPhase())->toBe(ServerPhase::Processing)
        ->and(ServerPhase::Processing->isSafelyRetryable())->toBeFalse()
        ->and(ServerPhase::Processing->awaitsServerConfirmation())->toBeTrue();
});

it('marks every money-moving processing state as not retryable', function (string $enum): void {
    foreach ($enum::cases() as $case) {
        if ($case->serverPhase() === ServerPhase::Processing) {
            expect($case->serverPhase()->isSafelyRetryable())->toBeFalse();
        }
    }
})->with([PaymentStatus::class, SettlementStatus::class, PayoutStatus::class]);

it('treats only Confirmed as success', function (): void {
    $successful = array_values(array_filter(ServerPhase::cases(), static fn (ServerPhase $p): bool => $p->isSuccessful()));

    expect($successful)->toBe([ServerPhase::Confirmed]);
});

it('keeps cancelled and expired distinct from failed', function (): void {
    // "You cancelled this", "you took too long" and "it did not work" are three
    // different things to tell somebody, and only one is anybody's fault.
    expect(ServerPhase::Cancelled)->not->toBe(ServerPhase::Failed)
        ->and(ServerPhase::Expired)->not->toBe(ServerPhase::Failed)
        // Only the two blameless ones are safe to retry; a cancellation was a
        // decision and repeating the request would override it.
        ->and(ServerPhase::Expired->isSafelyRetryable())->toBeTrue()
        ->and(ServerPhase::Failed->isSafelyRetryable())->toBeTrue()
        ->and(ServerPhase::Cancelled->isSafelyRetryable())->toBeFalse();
});

// ------------------------------------------------------ projection integrity

it('projects every case of every registered enum', function (string $enum): void {
    // PHP requires the match in serverPhase() to be exhaustive, so this fails
    // at construction rather than assertion if a case is unclassified — which
    // is the point of putting the projection on the enum.
    foreach ($enum::cases() as $case) {
        expect($case->serverPhase())->toBeInstanceOf(ServerPhase::class);
    }
})->with($projected);

it('implements the marker interface on every projected enum', function (string $enum): void {
    expect(is_subclass_of($enum, ServerAuthoritative::class))->toBeTrue();
})->with($projected);

it('gives a legacy delivery alias the same phase as its canonical state', function (): void {
    // A delivery must not appear to change phase because of when its row was
    // written. `assigned` predates M26; `accepted` is the same situation.
    expect(DeliveryStatus::Assigned->serverPhase())->toBe(DeliveryStatus::Accepted->serverPhase())
        ->and(DeliveryStatus::EnRoute->serverPhase())->toBe(DeliveryStatus::InTransit->serverPhase());
});

it('agrees with each context on which states are terminal', function (string $enum): void {
    // The coarse view must not contradict the precise one. Where a context says
    // a state is terminal, the phase must be terminal too — otherwise a client
    // waits for an answer that has already arrived.
    foreach ($enum::cases() as $case) {
        if (method_exists($case, 'isTerminal') && $case->isTerminal()) {
            expect($case->serverPhase()->isTerminal())
                ->toBeTrue("{$enum}::{$case->name} is terminal but its phase is not.");
        }
    }
})->with([AssignmentState::class, OfferState::class, DispatchState::class, DeliveryStatus::class]);

it('reads a refunded payment as confirmed, not failed', function (): void {
    // The money moved and then moved back: two settled facts, not an
    // unsuccessful one. The refund carries its own phase.
    expect(PaymentStatus::Refunded->serverPhase())->toBe(ServerPhase::Confirmed)
        ->and(PaymentStatus::PartiallyRefunded->serverPhase())->toBe(ServerPhase::Confirmed)
        ->and(RefundStatus::Pending->serverPhase())->toBe(ServerPhase::Pending);
});

it('separates an expired offer from a declined one', function (): void {
    // A rider who ran out of time and one who said no are different facts.
    expect(OfferState::Expired->serverPhase())->toBe(ServerPhase::Expired)
        ->and(OfferState::Declined->serverPhase())->toBe(ServerPhase::Cancelled);
});

it('never treats a draft as either in flight or settled', function (): void {
    expect(ServerPhase::Draft->isInFlight())->toBeFalse()
        ->and(ServerPhase::Draft->isTerminal())->toBeFalse();
});

it('gives every phase customer-safe wording', function (): void {
    foreach (ServerPhase::cases() as $phase) {
        expect($phase->explain())->not->toBe('');
    }
});
