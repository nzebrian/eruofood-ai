<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Application\Port;

/**
 * Screens review text for disallowed content. The default adapter is an offline
 * word-list matcher; an AI-backed adapter (via the AI engine's published
 * contract) can be bound in when enabled. A clean result lets a review
 * auto-publish (under post-moderation); a flagged result holds it for a human.
 */
interface ContentModerator
{
    /**
     * @return array{ok: bool, reason: string|null}
     */
    public function screen(string $text): array;
}
