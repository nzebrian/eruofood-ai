<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Public;

use EruoFood\PublicApi\Application\Service\PublicResourceService;
use EruoFood\PublicApi\Application\Transformer\DataResourceTransformer;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public nutrition data (scope: nutrition:read). Serves the Nutrition context's
 * published items and their per-serving nutrition panels, transformed into the
 * stable external shape. Items are addressed by their resource id.
 */
final class NutritionController
{
    use RespondsWithEnvelope;

    public function __construct(
        private readonly PublicResourceService $resources,
        private readonly DataResourceTransformer $transformer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->resources->nutritionItems($this->resourceQuery($request));

        return $this->collection($page, fn ($n): array => $this->transformer->nutrition($n));
    }

    public function show(string $id): JsonResponse
    {
        return $this->item($this->transformer->nutrition($this->resources->nutritionItem($id)));
    }
}
