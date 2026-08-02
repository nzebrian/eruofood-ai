<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Transformer;

use EruoFood\PublicApi\Domain\Read\FoodResource;
use EruoFood\PublicApi\Domain\Read\RecipeResource;

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
}
