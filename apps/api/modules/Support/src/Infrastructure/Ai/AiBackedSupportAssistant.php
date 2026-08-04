<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Ai;

use EruoFood\Ai\Contracts\AiAdviceRequest;
use EruoFood\Ai\Contracts\AiAdvisor;
use EruoFood\Support\Application\Port\AiSupportAssistant;
use Throwable;

/**
 * AI-backed agent assist via the AI engine's published contract. Summarises the
 * thread, drafts a reply and generates a customer insight. It depends only on
 * {@see AiAdvisor} (never the AI internals) and falls back to the offline
 * heuristic on any error, so a provider hiccup never breaks the agent workspace.
 */
final readonly class AiBackedSupportAssistant implements AiSupportAssistant
{
    public function __construct(
        private AiAdvisor $advisor,
        private HeuristicSupportAssistant $fallback,
    ) {
    }

    public function summariseThread(string $subject, array $thread): string
    {
        return $this->ask(
            'You summarise customer-support ticket threads for agents in 2-3 sentences.',
            "Subject: {$subject}\n\n".$this->renderThread($thread),
        ) ?? $this->fallback->summariseThread($subject, $thread);
    }

    public function suggestReply(string $subject, array $thread): string
    {
        return $this->ask(
            'You draft a concise, empathetic support reply for the agent to review before sending. No placeholders.',
            "Subject: {$subject}\n\n".$this->renderThread($thread),
        ) ?? $this->fallback->suggestReply($subject, $thread);
    }

    public function customerInsight(array $profile): string
    {
        return $this->ask(
            'You write a one-line CRM insight to help an agent understand a customer.',
            json_encode($profile) ?: '{}',
        ) ?? $this->fallback->customerInsight($profile);
    }

    private function ask(string $system, string $prompt): ?string
    {
        try {
            $result = $this->advisor->advise(new AiAdviceRequest($system, $prompt, null, true));
            $text = trim($result->text);

            return $text !== '' ? $text : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<array{author: string, body: string}> $thread
     */
    private function renderThread(array $thread): string
    {
        return implode("\n", array_map(
            static fn (array $m): string => strtoupper($m['author']).': '.$m['body'],
            $thread,
        ));
    }
}
