<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Port;

/**
 * Validates that a webhook destination URL is safe to call — the application's
 * defence against server-side request forgery (SSRF). Implementations enforce
 * the scheme/port policy and confirm the host resolves only to public, routable
 * addresses (never loopback, private, link-local or otherwise reserved ranges).
 *
 * It is invoked both when an endpoint is registered/updated and again
 * immediately before every delivery, so a host that is re-pointed at an internal
 * address after registration (DNS rebinding) is still refused at send time.
 */
interface WebhookUrlGuard
{
    /**
     * @throws \EruoFood\PublicApi\Domain\Exception\WebhookDestinationRejected
     *                                                                         when the URL violates the egress policy
     */
    public function assertAllowed(string $url): void;

    /** A non-throwing check, for callers that only need a boolean. */
    public function isAllowed(string $url): bool;
}
