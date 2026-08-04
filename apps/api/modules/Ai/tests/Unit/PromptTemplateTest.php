<?php

declare(strict_types=1);

use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Prompt\PromptTemplate;
use EruoFood\Ai\Domain\ValueObject\PromptVariables;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

function makeTemplate(string $system = 'You are a chef.', string $user = 'Cook {{ dish }} for {{ servings }}.'): PromptTemplate
{
    return PromptTemplate::create(
        id: 't1',
        feature: AiFeature::RecipeGeneration,
        version: 1,
        name: 'test',
        systemTemplate: $system,
        userTemplate: $user,
        model: null,
        variables: ['dish', 'servings'],
        active: true,
        createdAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
}

it('interpolates variables and leaves unknown tokens blank', function (): void {
    $rendered = makeTemplate()->render(PromptVariables::fromArray([
        'dish' => 'Jollof Rice',
        'servings' => 4,
        // 'missing' intentionally absent
    ]));

    expect($rendered->user)->toBe('Cook Jollof Rice for 4.')
        ->and($rendered->system)->toBe('You are a chef.');
});

it('renders a blank for tokens with no matching variable', function (): void {
    $rendered = makeTemplate(user: 'Hello {{ unknown }} world')->render(PromptVariables::fromArray([]));

    expect($rendered->user)->toBe('Hello  world');
});

it('produces a stable fingerprint for identical rendered content', function (): void {
    $vars = PromptVariables::fromArray(['dish' => 'Egusi', 'servings' => 2]);
    $a = makeTemplate()->render($vars)->fingerprint();
    $b = makeTemplate()->render($vars)->fingerprint();

    expect($a)->toBe($b)->and($a)->toHaveLength(64);
});

it('rejects an empty user template', function (): void {
    makeTemplate(user: '   ');
})->throws(InvalidArgumentException::class);

it('rejects a version below 1', function (): void {
    PromptTemplate::create('t', AiFeature::CookingTips, 0, 'n', '', 'body', null, [], true, new DateTimeImmutable());
})->throws(InvalidArgumentException::class);

it('flags conversational features as non-cacheable', function (): void {
    expect(AiFeature::CookingAssistant->isCacheable())->toBeFalse()
        ->and(AiFeature::RecipeGeneration->isCacheable())->toBeTrue();
});
