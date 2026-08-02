<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Public;

use EruoFood\PublicApi\Application\Service\PublicResourceService;
use EruoFood\PublicApi\Application\Transformer\DataResourceTransformer;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public food catalogue (scope: foods:read). Returns transformed resources — never internal shapes. */
final class FoodsController
{
    use RespondsWithEnvelope;

    public function __construct(
        private readonly PublicResourceService $resources,
        private readonly DataResourceTransformer $transformer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->resources->foods($this->resourceQuery($request));

        return $this->collection($page, fn ($f): array => $this->transformer->food($f));
    }

    public function show(string $slug): JsonResponse
    {
        return $this->item($this->transformer->food($this->resources->food($slug)));
    }
}
