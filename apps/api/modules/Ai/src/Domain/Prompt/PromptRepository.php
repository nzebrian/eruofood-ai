<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Prompt;

use EruoFood\Ai\Domain\Enum\AiFeature;

/**
 * Persistence port for versioned prompt templates (Repository Pattern).
 *
 * The domain/application layers depend on this interface only; the Eloquent
 * adapter lives in Infrastructure (Dependency Inversion).
 */
interface PromptRepository
{
    public function nextIdentity(): string;

    /** The currently active template for a feature, or null if none is seeded. */
    public function activeForFeature(AiFeature $feature): ?PromptTemplate;

    public function findById(string $id): ?PromptTemplate;

    /**
     * All versions of a feature's prompt, newest version first.
     *
     * @return list<PromptTemplate>
     */
    public function versionsForFeature(AiFeature $feature): array;

    /** The highest existing version number for a feature (0 if none). */
    public function latestVersion(AiFeature $feature): int;

    /** Persist a template. When it is active, all other versions are deactivated. */
    public function save(PromptTemplate $template): void;
}
