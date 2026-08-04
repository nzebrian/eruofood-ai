<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Moderation;

use EruoFood\Reviews\Application\Port\ContentModerator;

/**
 * The default, dependency-free content moderator: a case-insensitive word-list
 * matcher over the review text. It runs offline (no provider, no network) so
 * moderation always works; the AI-backed adapter wraps it when enabled.
 */
final readonly class WordlistContentModerator implements ContentModerator
{
    /**
     * @param list<string> $blocklist
     */
    public function __construct(private array $blocklist)
    {
    }

    public function screen(string $text): array
    {
        $haystack = ' '.strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text).' ';
        foreach ($this->blocklist as $word) {
            $needle = strtolower(trim($word));
            if ($needle === '') {
                continue;
            }
            if (str_contains($haystack, ' '.$needle.' ')) {
                return ['ok' => false, 'reason' => sprintf('blocked term: %s', $needle)];
            }
        }

        return ['ok' => true, 'reason' => null];
    }
}
