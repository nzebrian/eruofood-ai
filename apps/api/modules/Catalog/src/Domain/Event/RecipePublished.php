<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

final readonly class RecipePublished implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(public string $recipeId)
    {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'catalog.recipe_published';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
