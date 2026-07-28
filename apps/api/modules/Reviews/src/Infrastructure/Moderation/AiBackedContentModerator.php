<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Moderation;

use EruoFood\Ai\Contracts\AiAdviceRequest;
use EruoFood\Ai\Contracts\AiAdvisor;
use EruoFood\Reviews\Application\Port\ContentModerator;
use Throwable;

/**
 * AI-backed content moderation via the AI engine's published contract. It first
 * runs the offline word-list (a fast, certain reject) and only escalates clean
 * text to the model, which classifies it as OK or flags a reason. It depends on
 * {@see AiAdvisor} alone and, on any provider error, falls back to the word-list
 * result — a provider hiccup can never block a submission.
 */
final readonly class AiBackedContentModerator implements ContentModerator
{
    public function __construct(
        private AiAdvisor $advisor,
        private WordlistContentModerator $fallback,
    ) {
    }

    public function screen(string $text): array
    {
        $wordlist = $this->fallback->screen($text);
        if (! $wordlist['ok']) {
            return $wordlist;
        }

        $trimmed = trim($text);
        if ($trimmed === '') {
            return ['ok' => true, 'reason' => null];
        }

        try {
            $result = $this->advisor->advise(new AiAdviceRequest(
                'You are a content-moderation classifier for product/vendor reviews. '
                .'Reply with exactly "OK" if the text is acceptable, or "FLAG: <short reason>" '
                .'if it contains hate speech, harassment, spam, or explicit content.',
                $trimmed,
                null,
                true,
            ));
            $answer = trim($result->text);
        } catch (Throwable) {
            return $wordlist;
        }

        if (stripos($answer, 'FLAG') === 0) {
            $reason = trim(substr($answer, strpos($answer, ':') !== false ? (int) strpos($answer, ':') + 1 : 4));

            return ['ok' => false, 'reason' => $reason !== '' ? $reason : 'flagged by AI moderation'];
        }

        return ['ok' => true, 'reason' => null];
    }
}
