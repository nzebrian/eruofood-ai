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
     * The concrete document types a scope resolves to — the single authority on
     * what any read may return (M38-SEC-001).
     *
     * `Global` is the PUBLIC fan-out. It is *derived* from the cases rather
     * than listed literally, so adding a new admin-only case excludes it from
     * Global automatically. A hand-maintained list is the kind of thing that
     * drifts, and the drift is silent: the leak this replaces existed because
     * two query methods treated `Global` as "no type filter at all" while this
     * method said otherwise, and nothing reconciled the two.
     *
     * Every other scope maps to itself, including the admin-only ones — the
     * authorisation decision belongs to {@see
     * \EruoFood\Search\Domain\Access\SearchScopeGate}, not to this method.
     *
     * @return list<self>
     */
    public function documentTypes(): array
    {
        if ($this !== self::Global) {
            return [$this];
        }

        return array_values(array_filter(
            self::cases(),
            static fn (self $t): bool => $t !== self::Global && ! $t->isAdminOnly(),
        ));
    }

    /**
     * The same set as raw column values, for the query layer.
     *
     * Every repository read constrains on this. There is deliberately no way to
     * ask the index for "all types": the absence of a filter is what leaked.
     *
     * @return list<string>
     */
    public function documentTypeValues(): array
    {
        return array_map(static fn (self $t): string => $t->value, $this->documentTypes());
    }

    /**
     * Every scope a member of the public may legitimately search (M39-SEC-001).
     *
     * This is about SCOPES A CALLER REQUESTED, not about document types, and the
     * difference is load-bearing. `search_query_log.type` stores the scope named
     * on the request, and the default public search names `global` — so the set
     * here is `documentTypes()` **plus `Global` itself**. Filtering the query log
     * on `Global->documentTypeValues()` alone would drop `global`, which is the
     * single most common public search, and public trending would quietly empty
     * out. A privacy fix that silently disables the feature it protects is not a
     * fix.
     *
     * Derived from the cases minus the admin-only ones, so a new admin-only
     * scope is excluded here for the same reason it is excluded from the Global
     * fan-out: because nobody has to remember to exclude it.
     *
     * @return list<string>
     */
    public static function publicScopeValues(): array
    {
        return array_values(array_map(
            static fn (self $t): string => $t->value,
            array_filter(self::cases(), static fn (self $t): bool => ! $t->isAdminOnly()),
        ));
    }
}
