<?php

declare(strict_types=1);

use EruoFood\Notifications\Domain\Enum\ConversationType;
use EruoFood\Notifications\Domain\Enum\MessageType;
use EruoFood\Notifications\Domain\Exception\NotificationsNotAuthorized;
use EruoFood\Notifications\Domain\Messaging\Conversation;
use EruoFood\Notifications\Domain\Messaging\Message;

it('guards participation', function (): void {
    $c = Conversation::open('c1', ConversationType::CustomerVendor, ['u1', 'u2'], null, null, new DateTimeImmutable());
    expect($c->hasParticipant('u1'))->toBeTrue()->and($c->hasParticipant('u3'))->toBeFalse();
    expect(fn () => $c->assertParticipant('u3'))->toThrow(NotificationsNotAuthorized::class);
});

it('tracks read receipts, starting with the sender', function (): void {
    $m = Message::create('m1', 'c1', 'u1', MessageType::Text, 'hi', [], new DateTimeImmutable());
    expect($m->isReadBy('u1'))->toBeTrue()->and($m->isReadBy('u2'))->toBeFalse();
    $m->markReadBy('u2');
    $m->markReadBy('u2'); // idempotent
    expect($m->readBy())->toBe(['u1', 'u2']);
});
