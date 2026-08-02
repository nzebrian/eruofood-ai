<?php

declare(strict_types=1);

use EruoFood\PublicApi\Application\Service\WebhookSigner;
use EruoFood\PublicApi\Domain\Enum\DeliveryStatus;
use EruoFood\PublicApi\Domain\Webhook\WebhookDelivery;

it('signs and verifies HMAC payloads', function (): void {
    $signer = new WebhookSigner();
    $ts = 1_700_000_000;
    $sig = $signer->sign('{"a":1}', 'whsec', $ts);
    expect($signer->verify('{"a":1}', 'whsec', $ts, $sig, 300, $ts))->toBeTrue()
        ->and($signer->verify('{"a":2}', 'whsec', $ts, $sig, 300, $ts))->toBeFalse()
        ->and($signer->verify('{"a":1}', 'other', $ts, $sig, 300, $ts))->toBeFalse();
});

it('rejects signatures outside the replay tolerance', function (): void {
    $signer = new WebhookSigner();
    $ts = 1_700_000_000;
    $sig = $signer->sign('{}', 'whsec', $ts);
    expect($signer->verify('{}', 'whsec', $ts, $sig, 300, $ts + 301))->toBeFalse()
        ->and($signer->verify('{}', 'whsec', $ts, $sig, 300, $ts + 60))->toBeTrue();
});

it('retries with exponential backoff then fails at the ceiling', function (): void {
    $now = new DateTimeImmutable('2027-03-10T10:00:00Z');
    $d = WebhookDelivery::queue('d1', 'w1', 'evt1', 'review.published', '{}', $now);

    $d->recordAttempt(false, 500, 'e', 3, 30, $now);
    expect($d->status())->toBe(DeliveryStatus::Retrying)
        ->and($d->nextAttemptAt()->getTimestamp())->toBe($now->getTimestamp() + 30);

    $d->recordAttempt(false, 500, 'e', 3, 30, $now);
    expect($d->nextAttemptAt()->getTimestamp())->toBe($now->getTimestamp() + 60);

    $d->recordAttempt(false, 500, 'e', 3, 30, $now);
    expect($d->status())->toBe(DeliveryStatus::Failed)
        ->and($d->status()->isTerminal())->toBeTrue();
});

it('marks a successful attempt delivered', function (): void {
    $now = new DateTimeImmutable();
    $d = WebhookDelivery::queue('d1', 'w1', 'evt1', 'review.published', '{}', $now);
    $d->recordAttempt(true, 200, null, 5, 30, $now);
    expect($d->status())->toBe(DeliveryStatus::Delivered)
        ->and($d->deliveredAt())->not->toBeNull()
        ->and($d->nextAttemptAt())->toBeNull();
});
