<?php

declare(strict_types=1);

use EruoFood\Payments\Application\DTO\GatewayResult;
use EruoFood\Payments\Domain\Enum\GatewayOutcome;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

it('treats only a confirmed outcome as money moved', function (): void {
    expect(GatewayOutcome::Succeeded->isConfirmed())->toBeTrue()
        ->and(GatewayOutcome::Processing->isConfirmed())->toBeFalse()
        ->and(GatewayOutcome::Failed->isConfirmed())->toBeFalse()
        ->and(GatewayOutcome::Unknown->isConfirmed())->toBeFalse();
});

it('allows a retry only after an explicit failure', function (): void {
    // The invariant the whole enum exists for: an unknown transfer is not
    // retryable, because it may already have paid the merchant.
    expect(GatewayOutcome::Failed->isSafelyRetryable())->toBeTrue()
        ->and(GatewayOutcome::Unknown->isSafelyRetryable())->toBeFalse()
        ->and(GatewayOutcome::Processing->isSafelyRetryable())->toBeFalse()
        ->and(GatewayOutcome::Succeeded->isSafelyRetryable())->toBeFalse();
});

it('requires reconciliation for an unknown outcome and nothing else', function (): void {
    $requiring = array_values(array_filter(
        GatewayOutcome::cases(),
        static fn (GatewayOutcome $o): bool => $o->requiresReconciliation(),
    ));

    expect($requiring)->toBe([GatewayOutcome::Unknown]);
});

it('projects unknown onto a phase the platform already refuses to retry', function (): void {
    expect(GatewayOutcome::Unknown->serverPhase())->toBe(ServerPhase::Processing)
        ->and(GatewayOutcome::Unknown->serverPhase()->isSafelyRetryable())->toBeFalse()
        ->and(GatewayOutcome::Unknown->serverPhase()->isSuccessful())->toBeFalse()
        ->and(GatewayOutcome::Succeeded->serverPhase())->toBe(ServerPhase::Confirmed)
        ->and(GatewayOutcome::Failed->serverPhase())->toBe(ServerPhase::Failed);
});

it('resolves an unrecognised legacy failure to unknown rather than failed', function (): void {
    // The safe side of the mistake. An adapter that defaulted `success:false`
    // on an exception must not hand settlement a licence to retry.
    expect(GatewayOutcome::fromLegacy(false, ''))->toBe(GatewayOutcome::Unknown)
        ->and(GatewayOutcome::fromLegacy(false, 'timeout'))->toBe(GatewayOutcome::Unknown)
        ->and(GatewayOutcome::fromLegacy(false, 'error'))->toBe(GatewayOutcome::Unknown)
        ->and(GatewayOutcome::fromLegacy(false, 'failed'))->toBe(GatewayOutcome::Failed)
        ->and(GatewayOutcome::fromLegacy(false, 'DECLINED'))->toBe(GatewayOutcome::Failed)
        ->and(GatewayOutcome::fromLegacy(true, 'succeeded'))->toBe(GatewayOutcome::Succeeded)
        ->and(GatewayOutcome::fromLegacy(true, 'processing'))->toBe(GatewayOutcome::Processing);
});

it('always reports unknown from a transport failure', function (): void {
    expect(GatewayOutcome::fromTransportFailure())->toBe(GatewayOutcome::Unknown);
});

it('derives the legacy success flag from the outcome so the two cannot disagree', function (): void {
    foreach (GatewayOutcome::cases() as $outcome) {
        $result = GatewayResult::of($outcome, 'ref_1');

        expect($result->outcome())->toBe($outcome)
            ->and($result->success)->toBe($outcome->isConfirmed());
    }
});

it('reads an outcome from a legacy result that never supplied one', function (): void {
    // Every one of the seven shipped adapters builds results this way.
    $legacy = new GatewayResult(true, 'ref_2', 'succeeded');
    $declined = new GatewayResult(false, 'ref_3', 'failed');
    $silent = new GatewayResult(false, 'ref_4', '');

    expect($legacy->outcome())->toBe(GatewayOutcome::Succeeded)
        ->and($declined->outcome())->toBe(GatewayOutcome::Failed)
        ->and($silent->outcome())->toBe(GatewayOutcome::Unknown);
});

it('marks an unknown result as unknown without claiming success', function (): void {
    $result = GatewayResult::unknown('ref_5', 'connection reset');

    expect($result->outcome())->toBe(GatewayOutcome::Unknown)
        ->and($result->success)->toBeFalse()
        ->and($result->status)->toBe('unknown');
});
