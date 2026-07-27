<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Port;

/**
 * AI-backed shopping intelligence. The infrastructure adapter bridges to the AI
 * module's published contract, so commerce never depends on AI internals
 * (cross-context integration through Contracts only).
 */
interface CommerceAdvisor
{
    /**
     * A short, friendly reason a shopper might like these products — used to
     * caption recommendation / cross-sell / up-sell carousels.
     *
     * @param list<string> $productNames
     */
    public function recommendationBlurb(string $context, array $productNames, ?string $userId): string;

    /**
     * Turn a natural-language grocery request into concrete shopping-list line
     * items (e.g. "ingredients for jollof rice for 6").
     *
     * @return list<string>
     */
    public function buildShoppingList(string $request, ?string $userId): array;

    /** Answer a free-text question from the smart shopping assistant. */
    public function assist(string $question, ?string $userId): string;
}
