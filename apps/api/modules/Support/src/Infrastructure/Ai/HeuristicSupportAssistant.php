<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Ai;

use EruoFood\Support\Application\Port\AiSupportAssistant;

/**
 * The default, offline agent-assist adapter. It produces useful, deterministic
 * summaries/suggestions/insights from the ticket text and profile without any
 * external call — so the feature always works, and an AI-backed adapter can be
 * bound in when enabled for higher quality.
 */
final class HeuristicSupportAssistant implements AiSupportAssistant
{
    public function summariseThread(string $subject, array $thread): string
    {
        $count = count($thread);
        $last = $thread[$count - 1]['body'] ?? '';
        $lastSnippet = mb_substr(trim($last), 0, 200);

        return sprintf(
            'Ticket "%s" has %d message(s). Most recent: %s',
            $subject,
            $count,
            $lastSnippet === '' ? '(no content)' : $lastSnippet,
        );
    }

    public function suggestReply(string $subject, array $thread): string
    {
        return sprintf(
            "Hi, thanks for contacting EruoFood support about \"%s\". "
            ."I'm looking into this now and will update you shortly. "
            .'Could you share any additional detail (order reference, screenshots) that might help?',
            $subject,
        );
    }

    public function customerInsight(array $profile): string
    {
        $segment = (string) ($profile['segment'] ?? 'new');
        $orders = (int) ($profile['orders'] ?? 0);
        $spent = (int) ($profile['total_spent_minor'] ?? 0);
        $tickets = (int) ($profile['tickets'] ?? 0);

        return sprintf(
            '%s customer: %d order(s), ₦%s lifetime spend, %d support ticket(s). %s',
            ucfirst($segment),
            $orders,
            number_format($spent / 100),
            $tickets,
            $segment === 'vip' ? 'Prioritise and handle with extra care.' : 'Standard handling.',
        );
    }
}
