<?php

declare(strict_types=1);

use EruoFood\Ai\Application\Service\AiResponseParser;
use EruoFood\Ai\Domain\Exception\AiGenerationFailed;

beforeEach(function (): void {
    $this->parser = new AiResponseParser();
});

it('decodes a bare JSON object', function (): void {
    expect($this->parser->toArray('{"title":"Jollof","servings":4}'))
        ->toBe(['title' => 'Jollof', 'servings' => 4]);
});

it('extracts JSON from a fenced code block', function (): void {
    $text = "Here is your recipe:\n```json\n{\"title\":\"Egusi\"}\n```\nEnjoy!";

    expect($this->parser->toArray($text))->toBe(['title' => 'Egusi']);
});

it('extracts JSON embedded in surrounding prose', function (): void {
    $text = 'Sure! {"suggestions":["a","b"]} Hope that helps.';

    expect($this->parser->toArray($text))->toBe(['suggestions' => ['a', 'b']]);
});

it('decodes a top-level JSON array', function (): void {
    expect($this->parser->toArray('[1, 2, 3]'))->toBe([1, 2, 3]);
});

it('throws when no JSON can be recovered', function (): void {
    $this->parser->toArray('there is no json here');
})->throws(AiGenerationFailed::class);

it('strips markdown fences from plain text answers', function (): void {
    expect($this->parser->toText("```\nsome tips\n```"))->toBe('some tips')
        ->and($this->parser->toText('  spaced  '))->toBe('spaced');
});
