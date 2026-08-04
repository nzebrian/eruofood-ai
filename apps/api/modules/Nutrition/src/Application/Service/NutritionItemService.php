<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Service;

use EruoFood\Nutrition\Application\Input\NutritionItemInput;
use EruoFood\Nutrition\Domain\Exception\NutritionNotFound;
use EruoFood\Nutrition\Domain\Item\NutritionItem;
use EruoFood\Nutrition\Domain\Item\NutritionItemRepository;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;

/** The nutrition database: public search/read and admin CRUD. */
final readonly class NutritionItemService
{
    public function __construct(private NutritionItemRepository $items)
    {
    }

    /**
     * @return Paginated<NutritionItem>
     */
    public function search(?string $term, ?string $category, int $page, int $perPage): Paginated
    {
        return $this->items->search($term, $category, max(1, $page), min(60, max(1, $perPage)));
    }

    /** @throws NutritionNotFound */
    public function get(string $id): NutritionItem
    {
        return $this->items->findById($id) ?? throw NutritionNotFound::of('nutrition item', $id);
    }

    public function create(NutritionItemInput $input): NutritionItem
    {
        $slug = Slug::fromTitle($input->name);
        if ($this->items->slugExists((string) $slug)) {
            throw new InvalidArgumentException(sprintf('A nutrition item with slug "%s" already exists.', $slug));
        }

        $item = NutritionItem::create(
            id: $this->items->nextIdentity(),
            name: $input->name,
            slug: $slug,
            category: $input->category,
            servingSize: $input->servingSize,
            facts: $input->facts,
            foodId: $input->foodId,
        );
        $this->items->save($item);

        return $item;
    }

    public function update(string $id, NutritionItemInput $input): NutritionItem
    {
        $item = $this->get($id);
        $item->update($input->name, $input->category, $input->servingSize, $input->facts, $input->foodId);
        $this->items->save($item);

        return $item;
    }

    public function delete(string $id): void
    {
        $this->get($id);
        $this->items->delete($id);
    }
}
