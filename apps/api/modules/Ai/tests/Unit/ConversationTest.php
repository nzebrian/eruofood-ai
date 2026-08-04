<?php

declare(strict_types=1);

use EruoFood\Ai\Domain\Conversation\Conversation;
use EruoFood\Ai\Domain\Enum\MessageRole;

it('appends turns in order and maps them to provider messages', function (): void {
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $conversation = Conversation::start('c1', 'user-1', 'How to cook egusi', $now);

    $conversation->addMessage(MessageRole::User, 'How do I cook egusi?', $now);
    $conversation->addMessage(MessageRole::Assistant, 'Start by blending melon seeds…', $now->modify('+1 second'));

    expect($conversation->messageCount())->toBe(2)
        ->and($conversation->userId())->toBe('user-1');

    $messages = $conversation->toAiMessages();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe(MessageRole::User)
        ->and($messages[1]->content)->toContain('blending');
});

it('can be renamed', function (): void {
    $conversation = Conversation::start('c1', 'user-1', 'Untitled', new DateTimeImmutable());
    $conversation->rename('Egusi soup help');

    expect($conversation->title())->toBe('Egusi soup help');
});
