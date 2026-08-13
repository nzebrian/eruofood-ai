<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Webhook;

use EruoFood\Verification\Domain\Enum\ProviderName;

/**
 * Exactly-once store for inbound provider callbacks.
 *
 * There is deliberately no `seen()` companion to `claim()`. Asking "have I seen
 * this?" and then recording it afterwards leaves a window in which two
 * simultaneous redeliveries both pass the check — which is precisely the defect
 * M23 removed from the payments webhook path. The claim *is* the check.
 */
interface WebhookEventRepository
{
    /**
     * Take exclusive ownership of a provider event.
     *
     * @return bool false when another delivery already owns it
     */
    public function claim(ProviderName $provider, string $providerEventId, string $signatureScheme): bool;
}
