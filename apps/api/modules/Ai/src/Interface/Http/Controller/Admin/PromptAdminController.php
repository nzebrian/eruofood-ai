<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Controller\Admin;

use EruoFood\Ai\Application\Input\PromptInput;
use EruoFood\Ai\Application\Service\AiPresenter;
use EruoFood\Ai\Application\Service\PromptAdminService;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Prompt\PromptTemplate;
use EruoFood\Ai\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Ai\Interface\Http\Request\PromptRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin management of the versioned Prompt Management System (RBAC: admin). */
final readonly class PromptAdminController
{
    use RespondsWithData;

    public function __construct(
        private PromptAdminService $prompts,
        private AiPresenter $presenter,
    ) {
    }

    /** List every version of a feature's prompt (newest first). */
    public function index(Request $request): JsonResponse
    {
        $feature = AiFeature::from((string) $request->string('feature'));
        $versions = array_map(
            fn (PromptTemplate $t): array => $this->presenter->prompt($t),
            $this->prompts->versions($feature),
        );

        return $this->data($versions);
    }

    /** Publish a new prompt version. */
    public function store(PromptRequest $request): JsonResponse
    {
        $template = $this->prompts->createVersion(PromptInput::fromArray($request->validated()));

        return $this->data($this->presenter->prompt($template), 201);
    }

    /** Roll the active version to an existing template. */
    public function activate(string $id): JsonResponse
    {
        return $this->data($this->presenter->prompt($this->prompts->activate($id)));
    }
}
