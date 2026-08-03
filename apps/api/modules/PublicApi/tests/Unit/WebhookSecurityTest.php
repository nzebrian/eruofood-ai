<?php

declare(strict_types=1);

use EruoFood\PublicApi\Domain\Exception\WebhookDestinationRejected;
use EruoFood\PublicApi\Infrastructure\Webhook\NetworkWebhookUrlGuard;

/**
 * SSRF/egress policy for webhook destinations. IP-literal hosts need no DNS, so
 * these assertions exercise the real scheme/port/credential/private-range logic
 * deterministically.
 */
function prodGuard(): NetworkWebhookUrlGuard
{
    return new NetworkWebhookUrlGuard(
        allowedSchemes: ['https'],
        enforceHttps: true,
        allowedPorts: [443, 80],
        blockPrivateNetworks: true,
        allowedHosts: [],
    );
}

it('rejects non-https destinations when https is enforced', function (): void {
    expect(prodGuard()->isAllowed('http://example.com/hook'))->toBeFalse();
});

it('blocks loopback, private, link-local and CGNAT ranges (IPv4)', function (string $url): void {
    expect(prodGuard()->isAllowed($url))->toBeFalse();
})->with([
    'https://127.0.0.1/hook',
    'https://10.0.0.5/hook',
    'https://172.16.5.4/hook',
    'https://192.168.1.1/hook',
    'https://169.254.169.254/latest/meta-data', // cloud metadata endpoint
    'https://100.64.0.1/hook',
    'https://0.0.0.0/hook',
]);

it('blocks loopback, ULA, link-local and mapped ranges (IPv6)', function (string $url): void {
    expect(prodGuard()->isAllowed($url))->toBeFalse();
})->with([
    'https://[::1]/hook',
    'https://[fd00::1]/hook',
    'https://[fe80::1]/hook',
    'https://[::ffff:127.0.0.1]/hook',
]);

it('rejects credentials, disallowed ports and malformed urls', function (): void {
    $guard = prodGuard();
    expect($guard->isAllowed('https://user:pass@8.8.8.8/hook'))->toBeFalse()
        ->and($guard->isAllowed('https://8.8.8.8:8080/hook'))->toBeFalse()
        ->and($guard->isAllowed('ftp://8.8.8.8/hook'))->toBeFalse()
        ->and($guard->isAllowed('not-a-url'))->toBeFalse();
});

it('throws a typed rejection carrying a stable error code', function (): void {
    prodGuard()->assertAllowed('https://127.0.0.1/hook');
})->throws(WebhookDestinationRejected::class);

it('allows legitimate public destinations', function (string $url): void {
    expect(prodGuard()->isAllowed($url))->toBeTrue();
})->with([
    'https://8.8.8.8/hook',
    'https://8.8.8.8:443/hook',
    'https://[2001:4860:4860::8888]/hook',
]);

it('tolerates http to public hosts only outside production', function (): void {
    $dev = new NetworkWebhookUrlGuard(
        allowedSchemes: ['https', 'http'],
        enforceHttps: false,
        allowedPorts: [443, 80],
        blockPrivateNetworks: true,
        allowedHosts: [],
    );
    expect($dev->isAllowed('http://8.8.8.8/hook'))->toBeTrue()
        ->and($dev->isAllowed('http://127.0.0.1/hook'))->toBeFalse();
});

it('enforces an explicit host allowlist when configured', function (): void {
    $guard = new NetworkWebhookUrlGuard(
        allowedSchemes: ['https'],
        enforceHttps: true,
        allowedPorts: [443],
        blockPrivateNetworks: true,
        allowedHosts: ['hooks.partner.example'],
    );
    expect($guard->isAllowed('https://evil.example/hook'))->toBeFalse();
});
