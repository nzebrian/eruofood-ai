<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Access;

use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Exception\SearchNotAuthorized;

/**
 * The one place a Search scope is authorised (M38-SEC-001).
 *
 * Before M38 the admin-only rule lived inside `SearchService::search()` and
 * nowhere else. `AutocompleteService` and `RecommendationService` carried no
 * check at all, and both sit behind PUBLIC, unauthenticated routes that accept
 * `?type=user`. `suggest()` would have happily returned the titles of every
 * indexed user document to an anonymous caller.
 *
 * Nothing leaked, but only because no `UserSourceProvider` exists — the
 * protection was "that type is never indexed", which is not a protection. It is
 * a race between an attacker and whoever adds the indexer.
 *
 * So the decision moved here, and every read path calls it. Adding a new
 * endpoint that forgets to is caught by
 * `modules/Search/tests/Feature/SearchAuthorizationTest.php`, which enumerates
 * the public routes and demands a rejection from each.
 */
final readonly class SearchScopeGate
{
    /**
     * Resolve the scope a caller may read, or refuse.
     *
     * A null type means "no scope was named" and resolves to the public Global
     * scope — which deliberately excludes admin-only types.
     *
     * @throws SearchNotAuthorized when the caller may not read this scope
     */
    public function authorize(?SearchType $type, bool $isAdmin): SearchType
    {
        $resolved = $type ?? SearchType::Global;

        if ($resolved->isAdminOnly() && ! $isAdmin) {
            throw new SearchNotAuthorized('This search scope is restricted to administrators.');
        }

        return $resolved;
    }

    /**
     * Whether a scope is readable, without throwing.
     *
     * For callers that need to branch rather than abort. It exists so nobody is
     * tempted to re-implement the rule inline with a `!==` against
     * `SearchType::User`, which is exactly how the rule failed to spread the
     * first time.
     */
    public function allows(?SearchType $type, bool $isAdmin): bool
    {
        return ! ($type ?? SearchType::Global)->isAdminOnly() || $isAdmin;
    }
}
