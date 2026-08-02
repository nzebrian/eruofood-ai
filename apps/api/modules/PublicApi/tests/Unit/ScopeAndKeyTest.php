<?php

declare(strict_types=1);

use EruoFood\PublicApi\Application\Service\ScopeRegistry;
use EruoFood\PublicApi\Domain\ApiKey\ApiKey;
use EruoFood\PublicApi\Domain\Exception\PublicApiInvalidState;
use EruoFood\PublicApi\Domain\ValueObject\Scope;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use EruoFood\PublicApi\Infrastructure\Security\Sha256SecretHasher;

it('parses and validates scope format', function (): void {
    expect((new Scope('foods:read'))->resource)->toBe('foods');
    expect(fn () => new Scope('nope'))->toThrow(PublicApiInvalidState::class);
});

it('never widens a key beyond its application grant', function (): void {
    $granted = new ScopeSet(['foods:read', 'recipes:read']);
    $requested = new ScopeSet(['foods:read', 'orders:write']);
    expect($requested->intersect($granted)->toArray())->toBe(['foods:read']);
});

it('grants only explicitly held scopes', function (): void {
    $set = new ScopeSet(['foods:read']);
    expect($set->grants(new Scope('foods:read')))->toBeTrue()
        ->and($set->grants(new Scope('orders:write')))->toBeFalse();
});

it('rejects unknown scopes at registration', function (): void {
    $registry = new ScopeRegistry(['foods:read' => 'x']);
    expect($registry->validate(['foods:read'])->toArray())->toBe(['foods:read']);
    expect(fn () => $registry->validate(['ghost:read']))->toThrow(PublicApiInvalidState::class);
});

it('hashes secrets and verifies constant-time, never storing plaintext', function (): void {
    $hasher = new Sha256SecretHasher();
    $hash = $hasher->hash('s3cret');
    expect($hash)->not->toBe('s3cret')
        ->and($hasher->verify('s3cret', $hash))->toBeTrue()
        ->and($hasher->verify('nope', $hash))->toBeFalse();
});

it('treats revoked and expired keys as unusable', function (): void {
    $now = new DateTimeImmutable();
    $scopes = new ScopeSet(['foods:read']);
    $active = ApiKey::issue('k1', 'app1', 'k', 'efk_live_a', 'h', $scopes, null, $now);
    expect($active->isUsable($now))->toBeTrue();

    $expired = ApiKey::issue('k2', 'app1', 'k', 'efk_live_b', 'h', $scopes, $now->modify('-1 hour'), $now);
    expect($expired->isUsable($now))->toBeFalse();

    $active->revoke($now);
    expect($active->isUsable($now))->toBeFalse();
});
