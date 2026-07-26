<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Service;

/**
 * The AI Context Builder.
 *
 * Turns structured feature input (lists of ingredients, dietary flags, recipe
 * bodies) into clean, human-readable prompt fragments. Centralising this keeps
 * formatting consistent across features and out of the feature services, and
 * means a small change to how, say, an ingredient list is rendered updates every
 * prompt at once.
 */
final readonly class AiContextBuilder
{
    /**
     * Render a list as a newline bullet list, or a placeholder when empty.
     *
     * @param list<string> $items
     */
    public function bulletList(array $items, string $emptyLabel = 'none specified'): string
    {
        $clean = array_values(array_filter(array_map('trim', $items), static fn (string $i): bool => $i !== ''));
        if ($clean === []) {
            return $emptyLabel;
        }

        return implode("\n", array_map(static fn (string $i): string => '- '.$i, $clean));
    }

    /**
     * Render a list inline (comma-separated), or a placeholder when empty.
     *
     * @param list<string> $items
     */
    public function inlineList(array $items, string $emptyLabel = 'no preference'): string
    {
        $clean = array_values(array_filter(array_map('trim', $items), static fn (string $i): bool => $i !== ''));

        return $clean === [] ? $emptyLabel : implode(', ', $clean);
    }

    /**
     * Render structured recipe input (title + ingredients + steps) as a compact
     * text block the model can reason over.
     *
     * @param list<string> $ingredients
     * @param list<string> $steps
     */
    public function recipeBlock(string $title, array $ingredients, array $steps): string
    {
        return implode("\n", [
            'Title: '.$title,
            '',
            'Ingredients:',
            $this->bulletList($ingredients),
            '',
            'Steps:',
            $this->numberedList($steps),
        ]);
    }

    /**
     * @param list<string> $items
     */
    public function numberedList(array $items, string $emptyLabel = 'none specified'): string
    {
        $clean = array_values(array_filter(array_map('trim', $items), static fn (string $i): bool => $i !== ''));
        if ($clean === []) {
            return $emptyLabel;
        }

        $out = [];
        foreach ($clean as $i => $line) {
            $out[] = ($i + 1).'. '.$line;
        }

        return implode("\n", $out);
    }
}
