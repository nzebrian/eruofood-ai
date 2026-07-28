<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Enum;

/**
 * The kind of thing being searched. `Global` spans every indexed type; the rest
 * scope a query to one document type. Each value is also the `type` stored on a
 * {@see \EruoFood\Search\Domain\Document\SearchDocument}.
 */
enum SearchType: string
{
    case Global = 'global';
    case Recipe = 'recipe';
    case Food = 'food';
    case Ingredient = 'ingredient';
    case Restaurant = 'restaurant';
    case Vendor = 'vendor';
    case Product = 'product';
    case User = 'user';       // admin-only
    case Category = 'category';

    /** Whether this scope is restricted to administrators. */
    public function isAdminOnly(): bool
    {
        return $this === self::User;
    }

    /**
     * The concrete document types a scope resolves to. `Global` fans out to all
     * public types; every other scope maps to itself.
     *
     * @return list<self>
     */
    public function documentTypes(): array
    {
        return match ($this) {
            self::Global => [
                self::Recipe, self::Food, self::Ingredient,
                self::Restaurant, self::Vendor, self::Product, self::Category,
            ],
            default => [$this],
        };
    }
}
