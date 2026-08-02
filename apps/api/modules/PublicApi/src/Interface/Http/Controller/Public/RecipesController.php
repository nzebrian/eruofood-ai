<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Public;

use EruoFood\PublicApi\Application\Service\PublicResourceService;
use EruoFood\PublicApi\Application\Transformer\DataResourceTransformer;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public recipe catalogue (scope: recipes:read). */
final class RecipesController
{
    use RespondsWithEnvelope;

    public function __construct(
        private readonly PublicResourceService $resources,
        private readonly DataResourceTransformer $transformer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->resources->recipes($this->resourceQuery($request));

        return $this->collection($page, fn ($r): array => $this->transformer->recipe($r));
    }

    public function show(string $slug): JsonResponse
    {
        return $this->item($this->transformer->recipe($this->resources->recipe($slug)));
    }
}
