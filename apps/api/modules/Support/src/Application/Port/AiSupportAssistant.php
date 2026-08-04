<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Port;

/**
 * AI assistance for agents. Summarises a ticket thread, drafts a suggested reply,
 * and generates a customer insight. The default adapter is a lightweight,
 * offline heuristic; an AI-backed adapter (the AI engine's published contract)
 * is bound when `support.ai_assist` is enabled. Kept behind a port so the
 * pipeline never depends on the AI internals and always has a working default.
 */
interface AiSupportAssistant
{
    /**
     * @param list<array{author: string, body: string}> $thread
     */
    public function summariseThread(string $subject, array $thread): string;

    /**
     * @param list<array{author: string, body: string}> $thread
     */
    public function suggestReply(string $subject, array $thread): string;

    /**
     * @param array<string, scalar|null> $profile
     */
    public function customerInsight(array $profile): string;
}
