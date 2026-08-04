<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Transformer;

use EruoFood\PublicApi\Domain\Read\FoodResource;
use EruoFood\PublicApi\Domain\Read\MenuItemResource;
use EruoFood\PublicApi\Domain\Read\NutritionResource;
use EruoFood\PublicApi\Domain\Read\ProductCategoryResource;
use EruoFood\PublicApi\Domain\Read\ProductResource;
use EruoFood\PublicApi\Domain\Read\RecipeResource;
use EruoFood\PublicApi\Domain\Read\RestaurantResource;

/** Transforms public data read-models into the stable external JSON shape. */
final readonly class DataResourceTransformer
{
    /**
     * @return array<string, mixed>
     */
    public function food(FoodResource $f): array
    {
        return [
            'id' => $f->id,
            'slug' => $f->slug,
            'name' => $f->name,
            'description' => $f->description,
            'region' => $f->region,
            'image_url' => $f->imageUrl,
            'tags' => $f->tags,
            'updated_at' => $f->updatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recipe(RecipeResource $r): array
    {
        return [
            'id' => $r->id,
            'slug' => $r->slug,
            'title' => $r->title,
            'summary' => $r->summary,
            'prep_minutes' => $r->prepMinutes,
            'cook_minutes' => $r->cookMinutes,
            'difficulty' => $r->difficulty,
            'updated_at' => $r->updatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function restaurant(RestaurantResource $r): array
    {
        return [
            'id' => $r->id,
            'slug' => $r->slug,
            'name' => $r->name,
            'type' => $r->type,
            'category' => $r->category,
            'description' => $r->description,
            'featured' => $r->featured,
            'images' => $r->images,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function menuItem(MenuItemResource $m): array
    {
        return [
            'id' => $m->id,
            'restaurant_id' => $m->restaurantId,
            'category_id' => $m->categoryId,
            'name' => $m->name,
            'description' => $m->description,
            'price_minor' => $m->priceMinor,
            'currency' => $m->currency,
            'available' => $m->available,
            'tags' => $m->tags,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function product(ProductResource $p): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'kind' => $p->kind,
            'department' => $p->department,
            'description' => $p->description,
            'price_minor' => $p->priceMinor,
            'currency' => $p->currency,
            'category_id' => $p->categoryId,
            'images' => $p->images,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function productCategory(ProductCategoryResource $c): array
    {
        return [
            'id' => $c->id,
            'slug' => $c->slug,
            'name' => $c->name,
            'kind' => $c->kind,
            'parent_id' => $c->parentId,
            'sort_order' => $c->sortOrder,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function nutrition(NutritionResource $n): array
    {
        return [
            'id' => $n->id,
            'slug' => $n->slug,
            'name' => $n->name,
            'category' => $n->category,
            'food_id' => $n->foodId,
            'facts' => $n->facts,
        ];
    }
}
