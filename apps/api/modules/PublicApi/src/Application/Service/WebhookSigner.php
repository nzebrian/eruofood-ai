<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

/**
 * Signs and verifies webhook payloads with HMAC-SHA256 over
 * "{timestamp}.{payload}", the same scheme receivers reproduce. Verification is
 * constant-time and enforces a timestamp tolerance to prevent replay.
 */
final readonly class WebhookSigner
{
    public function sign(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    public function verify(string $payload, string $secret, int $timestamp, string $signature, int $toleranceSeconds, int $now): bool
    {
        if (abs($now - $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals($this->sign($payload, $secret, $timestamp), $signature);
    }
}
