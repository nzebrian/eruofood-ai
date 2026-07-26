<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Service;

use EruoFood\Ai\Domain\Exception\AiGenerationFailed;

/**
 * The AI Response Parser.
 *
 * LLMs return free text; structured features (recipe generation, meal
 * suggestions, substitutions) ask for JSON but models often wrap it in prose or
 * ```json fences. This parser extracts and decodes the JSON payload robustly, so
 * feature services receive clean arrays and never touch raw model output.
 */
final readonly class AiResponseParser
{
    /**
     * Decode a JSON object/array embedded in the model's text.
     *
     * @return array<mixed>
     *
     * @throws AiGenerationFailed when no valid JSON can be recovered
     */
    public function toArray(string $text): array
    {
        $candidate = $this->extractJson($text);

        try {
            /** @var array<mixed> $decoded */
            $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw AiGenerationFailed::unparseable();
        }

        if (! is_array($decoded)) {
            throw AiGenerationFailed::unparseable();
        }

        return $decoded;
    }

    /** Strip markdown fences and surrounding whitespace from a plain-text answer. */
    public function toText(string $text): string
    {
        $clean = preg_replace('/^```[a-zA-Z]*\n?|\n?```$/m', '', trim($text));

        return trim((string) $clean);
    }

    /** Locate the first JSON object/array in a possibly prose-wrapped response. */
    private function extractJson(string $text): string
    {
        $text = trim($text);

        // Prefer a fenced ```json … ``` block when present.
        if (preg_match('/```(?:json)?\s*(\{.*\}|\[.*\])\s*```/s', $text, $m) === 1) {
            return $m[1];
        }

        // Otherwise take the substring from the first { or [ to its matching last brace.
        $start = $this->firstBracePosition($text);
        if ($start === null) {
            throw AiGenerationFailed::unparseable();
        }

        $open = $text[$start];
        $close = $open === '{' ? '}' : ']';
        $end = strrpos($text, $close);
        if ($end === false || $end < $start) {
            throw AiGenerationFailed::unparseable();
        }

        return substr($text, $start, $end - $start + 1);
    }

    private function firstBracePosition(string $text): ?int
    {
        $brace = strpos($text, '{');
        $bracket = strpos($text, '[');

        if ($brace === false && $bracket === false) {
            return null;
        }
        if ($brace === false) {
            return $bracket === false ? null : $bracket;
        }
        if ($bracket === false) {
            return $brace;
        }

        return min($brace, $bracket);
    }
}
