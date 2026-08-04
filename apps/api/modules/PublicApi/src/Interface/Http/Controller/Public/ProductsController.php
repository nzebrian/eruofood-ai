<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Public;

use EruoFood\PublicApi\Application\Service\PublicResourceService;
use EruoFood\PublicApi\Application\Transformer\DataResourceTransformer;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public commerce products + categories (scope: products:read). Only the
 * published catalogue is exposed; the controller returns transformed resources,
 * never Commerce's internal Product/Category shapes.
 */
final class ProductsController
{
    use RespondsWithEnvelope;

    public function __construct(
        private readonly PublicResourceService $resources,
        private readonly DataResourceTransformer $transformer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->resources->products($this->resourceQuery($request));

        return $this->collection($page, fn ($p): array => $this->transformer->product($p));
    }

    public function show(string $slug): JsonResponse
    {
        return $this->item($this->transformer->product($this->resources->product($slug)));
    }

    public function categories(): JsonResponse
    {
        $categories = array_map(
            fn ($c): array => $this->transformer->productCategory($c),
            $this->resources->productCategories(),
        );

        return $this->item(['categories' => $categories]);
    }
}
