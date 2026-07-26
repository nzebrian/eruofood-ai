<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Service;

use EruoFood\Ai\Application\Input\PromptInput;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Exception\PromptNotFound;
use EruoFood\Ai\Domain\Prompt\PromptRepository;
use EruoFood\Ai\Domain\Prompt\PromptTemplate;
use EruoFood\Shared\Domain\Clock;

/**
 * Admin-side of the Prompt Management System: inspect a feature's prompt version
 * history, publish a new version, and roll the active version forward or back.
 *
 * New versions are always additive — the version number increments and prior
 * versions are retained — so a prompt can be reverted at any time.
 */
final readonly class PromptAdminService
{
    public function __construct(
        private PromptRepository $prompts,
        private Clock $clock,
    ) {
    }

    /** @return list<PromptTemplate> */
    public function versions(AiFeature $feature): array
    {
        return $this->prompts->versionsForFeature($feature);
    }

    /** Publish a new template version for a feature. */
    public function createVersion(PromptInput $input): PromptTemplate
    {
        $template = PromptTemplate::create(
            id: $this->prompts->nextIdentity(),
            feature: $input->feature,
            version: $this->prompts->latestVersion($input->feature) + 1,
            name: $input->name,
            systemTemplate: $input->systemTemplate,
            userTemplate: $input->userTemplate,
            model: $input->model,
            variables: $input->variables,
            active: $input->activate,
            createdAt: $this->clock->now(),
        );

        $this->prompts->save($template);

        return $template;
    }

    /** Make an existing version the active one for its feature. */
    public function activate(string $id): PromptTemplate
    {
        $template = $this->prompts->findById($id) ?? throw PromptNotFound::byId($id);
        $template->activate();
        $this->prompts->save($template);

        return $template;
    }
}
