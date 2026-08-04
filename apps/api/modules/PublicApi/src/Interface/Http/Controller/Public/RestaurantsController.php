<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Public;

use EruoFood\PublicApi\Application\Service\PublicResourceService;
use EruoFood\PublicApi\Application\Transformer\DataResourceTransformer;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public restaurants + menus (scope: restaurants:read). Only verified,
 * tradeable vendors and their available menu items are exposed; the controller
 * returns transformed resources, never Marketplace's internal shapes.
 */
final class RestaurantsController
{
    use RespondsWithEnvelope;

    public function __construct(
        private readonly PublicResourceService $resources,
        private readonly DataResourceTransformer $transformer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->resources->restaurants($this->resourceQuery($request));

        return $this->collection($page, fn ($r): array => $this->transformer->restaurant($r));
    }

    public function show(string $slug): JsonResponse
    {
        return $this->item($this->transformer->restaurant($this->resources->restaurant($slug)));
    }

    public function menu(string $id): JsonResponse
    {
        $items = array_map(
            fn ($m): array => $this->transformer->menuItem($m),
            $this->resources->restaurantMenu($id),
        );

        return $this->item(['restaurant_id' => $id, 'items' => $items]);
    }
}
