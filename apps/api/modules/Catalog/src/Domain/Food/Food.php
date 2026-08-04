<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Food;

use EruoFood\Catalog\Domain\Enum\ContentStatus;
use EruoFood\Catalog\Domain\Enum\FoodRegion;
use EruoFood\Catalog\Domain\Event\FoodPublished;
use EruoFood\Catalog\Domain\ValueObject\LocalName;
use EruoFood\Catalog\Domain\ValueObject\NutritionalInfo;
use EruoFood\Shared\Domain\AggregateRoot;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * Food aggregate root — a Nigerian dish/food item with its regional origin,
 * local names, nutrition, images, and (architecture-ready) video.
 */
final class Food extends AggregateRoot
{
    /**
     * @param list<LocalName> $localNames
     * @param list<string> $states
     * @param list<string> $images
     * @param list<string> $tags
     */
    private function __construct(
        private readonly string $id,
        private string $name,
        private Slug $slug,
        private ?string $description,
        private string $categoryId,
        private FoodRegion $region,
        private array $states,
        private array $localNames,
        private ?NutritionalInfo $nutrition,
        private array $images,
        private ?string $videoUrl,
        private array $tags,
        private ContentStatus $status,
    ) {
    }

    /**
     * @param list<LocalName> $localNames
     * @param list<string> $states
     * @param list<string> $tags
     */
    public static function create(
        string $id,
        string $name,
        Slug $slug,
        string $categoryId,
        FoodRegion $region,
        ?string $description = null,
        array $states = [],
        array $localNames = [],
        ?NutritionalInfo $nutrition = null,
        array $tags = [],
    ): self {
        return new self(
            $id,
            $name,
            $slug,
            $description,
            $categoryId,
            $region,
            $states,
            $localNames,
            $nutrition,
            [],
            null,
            $tags,
            ContentStatus::Draft,
        );
    }

    /**
     * @param list<LocalName> $localNames
     * @param list<string> $states
     * @param list<string> $images
     * @param list<string> $tags
     */
    public static function reconstitute(
        string $id,
        string $name,
        Slug $slug,
        ?string $description,
        string $categoryId,
        FoodRegion $region,
        array $states,
        array $localNames,
        ?NutritionalInfo $nutrition,
        array $images,
        ?string $videoUrl,
        array $tags,
        ContentStatus $status,
    ): self {
        return new self(
            $id,
            $name,
            $slug,
            $description,
            $categoryId,
            $region,
            $states,
            $localNames,
            $nutrition,
            $images,
            $videoUrl,
            $tags,
            $status,
        );
    }

    /**
     * @param list<LocalName> $localNames
     * @param list<string> $states
     * @param list<string> $tags
     */
    public function updateDetails(
        string $name,
        Slug $slug,
        ?string $description,
        string $categoryId,
        FoodRegion $region,
        array $states,
        array $localNames,
        ?NutritionalInfo $nutrition,
        array $tags,
    ): void {
        $this->name = $name;
        $this->slug = $slug;
        $this->description = $description;
        $this->categoryId = $categoryId;
        $this->region = $region;
        $this->states = $states;
        $this->localNames = $localNames;
        $this->nutrition = $nutrition;
        $this->tags = $tags;
    }

    public function addImage(string $path): void
    {
        $this->images[] = $path;
    }

    public function removeImage(string $path): void
    {
        $this->images = array_values(array_filter($this->images, static fn (string $p): bool => $p !== $path));
    }

    public function setVideoUrl(?string $url): void
    {
        $this->videoUrl = $url;
    }

    public function publish(): void
    {
        if ($this->status === ContentStatus::Published) {
            return;
        }
        $this->status = ContentStatus::Published;
        $this->recordThat(new FoodPublished($this->id));
    }

    public function archive(): void
    {
        $this->status = ContentStatus::Archived;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function categoryId(): string
    {
        return $this->categoryId;
    }

    public function region(): FoodRegion
    {
        return $this->region;
    }

    /** @return list<string> */
    public function states(): array
    {
        return $this->states;
    }

    /** @return list<LocalName> */
    public function localNames(): array
    {
        return $this->localNames;
    }

    public function nutrition(): ?NutritionalInfo
    {
        return $this->nutrition;
    }

    /** @return list<string> */
    public function images(): array
    {
        return $this->images;
    }

    public function videoUrl(): ?string
    {
        return $this->videoUrl;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function status(): ContentStatus
    {
        return $this->status;
    }
}
