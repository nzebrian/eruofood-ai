<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Application\Service;

use EruoFood\Catalog\Application\Input\FoodInput;
use EruoFood\Catalog\Application\Port\ImageStorage;
use EruoFood\Catalog\Domain\Exception\CatalogNotFound;
use EruoFood\Catalog\Domain\Food\Food;
use EruoFood\Catalog\Domain\Food\FoodRepository;
use EruoFood\Catalog\Domain\Food\FoodSearchCriteria;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;

/** Food database use cases: browse/search (public) + management (admin). */
final readonly class FoodService
{
    public function __construct(
        private FoodRepository $foods,
        private ImageStorage $images,
    ) {
    }

    /**
     * @return Paginated<Food>
     */
    public function search(FoodSearchCriteria $criteria, int $page, int $perPage): Paginated
    {
        return $this->foods->search($criteria, max(1, $page), min(60, max(1, $perPage)));
    }

    public function getBySlug(string $slug): Food
    {
        return $this->foods->findBySlug($slug) ?? throw CatalogNotFound::of('food', $slug);
    }

    public function getById(string $id): Food
    {
        return $this->load($id);
    }

    public function create(FoodInput $input): Food
    {
        $food = Food::create(
            id: $this->foods->nextIdentity(),
            name: $input->name,
            slug: $this->uniqueSlug($input->name),
            categoryId: $input->categoryId,
            region: $input->region,
            description: $input->description,
            states: $input->states,
            localNames: $input->localNames,
            nutrition: $input->nutrition,
            tags: $input->tags,
        );
        $this->foods->save($food);

        return $food;
    }

    public function update(string $id, FoodInput $input): Food
    {
        $food = $this->load($id);
        $slug = $food->name() === $input->name ? $food->slug() : $this->uniqueSlug($input->name, $id);

        $food->updateDetails(
            name: $input->name,
            slug: $slug,
            description: $input->description,
            categoryId: $input->categoryId,
            region: $input->region,
            states: $input->states,
            localNames: $input->localNames,
            nutrition: $input->nutrition,
            tags: $input->tags,
        );
        $this->foods->save($food);

        return $food;
    }

    public function publish(string $id): Food
    {
        $food = $this->load($id);
        $food->publish();
        $this->foods->save($food);

        return $food;
    }

    public function archive(string $id): Food
    {
        $food = $this->load($id);
        $food->archive();
        $this->foods->save($food);

        return $food;
    }

    public function addImage(string $id, string $contents, string $extension): Food
    {
        $food = $this->load($id);
        $path = $this->images->store("foods/{$id}", $contents, $extension);
        $food->addImage($path);
        $this->foods->save($food);

        return $food;
    }

    public function removeImage(string $id, string $path): Food
    {
        $food = $this->load($id);
        $food->removeImage($path);
        $this->foods->save($food);
        $this->images->delete($path);

        return $food;
    }

    public function setVideoUrl(string $id, ?string $url): Food
    {
        $food = $this->load($id);
        $food->setVideoUrl($url);
        $this->foods->save($food);

        return $food;
    }

    public function delete(string $id): void
    {
        $this->load($id);
        $this->foods->delete($id);
    }

    private function uniqueSlug(string $name, ?string $ignoreId = null): Slug
    {
        $base = Slug::fromTitle($name);
        $candidate = $base;
        $suffix = 1;

        while ($this->foods->existsBySlug($candidate->value)) {
            $existing = $this->foods->findBySlug($candidate->value);
            if ($ignoreId !== null && $existing !== null && $existing->id() === $ignoreId) {
                break;
            }
            $suffix++;
            $candidate = new Slug($base->value.'-'.$suffix);
        }

        return $candidate;
    }

    private function load(string $id): Food
    {
        return $this->foods->findById($id) ?? throw CatalogNotFound::of('food', $id);
    }
}
