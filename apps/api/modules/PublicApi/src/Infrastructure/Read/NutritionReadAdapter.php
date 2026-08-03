<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Read;

use EruoFood\Nutrition\Domain\Item\NutritionItem;
use EruoFood\Nutrition\Domain\Item\NutritionItemRepository;
use EruoFood\PublicApi\Domain\Read\NutritionReadPort;
use EruoFood\PublicApi\Domain\Read\NutritionResource;
use EruoFood\PublicApi\Domain\Read\ResourceQuery;
use EruoFood\Shared\Domain\Paginated;

/**
 * Adapts the Public API's {@see NutritionReadPort} onto the Nutrition context. A
 * sanctioned cross-context read seam: it consumes Nutrition's published item
 * repository (never its tables) and maps the item's serving size and nutrition
 * panel into the Public API's own DTO, keeping the external contract independent
 * of the Nutrition domain's value objects.
 */
final readonly class NutritionReadAdapter implements NutritionReadPort
{
    public function __construct(private NutritionItemRepository $items)
    {
    }

    public function items(ResourceQuery $query): Paginated
    {
        $page = $this->items->search(
            $query->search,
            $query->filters['category'] ?? null,
            $query->page,
            $query->perPage,
        );

        return new Paginated(
            array_map(fn (NutritionItem $i): NutritionResource => $this->toResource($i), $page->items),
            $page->total,
            $page->page,
            $page->perPage,
        );
    }

    public function item(string $slug): ?NutritionResource
    {
        // Nutrition items are addressed by id in the repository; the public slug
        // is the item id, so a paginated single-term lookup is not needed here —
        // the repository resolves the identity directly.
        $item = $this->items->findById($slug);

        return $item !== null ? $this->toResource($item) : null;
    }

    private function toResource(NutritionItem $i): NutritionResource
    {
        return new NutritionResource(
            $i->id(),
            (string) $i->slug(),
            $i->name(),
            $i->category(),
            $i->foodId(),
            [
                'serving_size' => $i->servingSize()->toArray(),
                'panel' => $i->facts()->toArray(),
            ],
        );
    }
}
