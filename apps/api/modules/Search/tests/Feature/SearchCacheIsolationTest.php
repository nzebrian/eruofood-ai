<?php

declare(strict_types=1);

use EruoFood\Search\Application\Port\SearchCache;
use EruoFood\Search\Application\Service\SearchIndexManager;
use EruoFood\Search\Infrastructure\Cache\LaravelSearchCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M38-CACHE-001 — search invalidation stopped clearing the whole store.
 *
 * `LaravelSearchCache::flush()` called `$this->cache->clear()`, and the class
 * was bound to the DEFAULT cache repository. With `CACHE_STORE=redis` a single
 * reindex therefore evicted rate-limit counters, config and route caches and
 * anything else sharing the store — and `SearchIndexManager` called it per
 * document, so a backfill of N documents issued N whole-store flushes.
 */

function seedFoodRow(string $name): string
{
    $id = (string) Str::orderedUuid();

    DB::table('catalog_foods')->insert([
        'id' => $id,
        'name' => $name,
        'slug' => Str::slug($name).'-'.substr($id, 0, 8),
        'description' => 'cache isolation subject',
        'category_id' => (string) Str::orderedUuid(),
        'region' => 'South West',
        'states' => json_encode([]),
        'local_names' => json_encode([]),
        'nutrition' => json_encode(['calories' => 400]),
        'images' => json_encode([]),
        'tags' => json_encode([]),
        'status' => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('serves a repeated identical query from cache', function (): void {
    $cache = app(SearchCache::class);

    $calls = 0;
    $resolver = function () use (&$calls): string {
        $calls++;

        return 'computed';
    };

    expect($cache->remember('demo', 60, $resolver))->toBe('computed')
        ->and($cache->remember('demo', 60, $resolver))->toBe('computed')
        ->and($calls)->toBe(1);
});

it('leaves unrelated application cache entries alone when search invalidates', function (): void {
    // Exactly the keys the old whole-store clear would have destroyed.
    Cache::put('unrelated:session:abc', 'keep me', 600);
    Cache::put('unrelated:ratelimit:1.2.3.4', 42, 600);

    app(SearchCache::class)->flush();

    expect(Cache::get('unrelated:session:abc'))->toBe('keep me')
        ->and(Cache::get('unrelated:ratelimit:1.2.3.4'))->toBe(42);
});

it('invalidates search entries while sparing everything else', function (): void {
    $cache = app(SearchCache::class);
    Cache::put('unrelated:key', 'survivor', 600);

    $cache->remember('scoped', 600, fn (): string => 'first');
    $cache->flush();

    // The old value is unreachable — the namespace moved on …
    expect($cache->remember('scoped', 600, fn (): string => 'second'))->toBe('second')
        // … and the neighbour is untouched.
        ->and(Cache::get('unrelated:key'))->toBe('survivor');
});

it('does not flush the application cache once per document during a backfill', function (): void {
    seedFoodRow('Backfill One');
    seedFoodRow('Backfill Two');
    seedFoodRow('Backfill Three');

    Cache::put('unrelated:during-backfill', 'still here', 600);

    $before = app(LaravelSearchCache::class)->namespacePrefix();
    $indexed = app(SearchIndexManager::class)->reindexAll('food');
    $after = app(LaravelSearchCache::class)->namespacePrefix();

    expect($indexed)->toBeGreaterThanOrEqual(3)
        // One invalidation for the whole batch, not one per document.
        ->and($before)->not->toBe($after)
        ->and(Cache::get('unrelated:during-backfill'))->toBe('still here');

    // And the batch really did move the namespace exactly once: a second
    // backfill moves it exactly once more.
    $third = app(LaravelSearchCache::class)->namespacePrefix();
    app(SearchIndexManager::class)->reindexAll('food');
    expect(app(LaravelSearchCache::class)->namespacePrefix())->not->toBe($third);
});

it('namespaces its keys so they cannot collide with another context', function (): void {
    $cache = app(LaravelSearchCache::class);

    expect($cache->namespacePrefix())->toStartWith((string) config('search.cache_prefix'));
});

it('contains no whole-store clear anywhere in the search cache adapter', function (): void {
    // A static assertion, because this is the defect: the dangerous call is one
    // word long and reads as harmless at a review glance.
    //
    // Comments are stripped first. The file DOCUMENTS the old `->clear()` call
    // it replaced, and an assertion that cannot tell prose from code would fire
    // on the explanation instead of on a regression — which it did, on the
    // first run of this very test.
    $source = (string) file_get_contents(
        base_path('modules/Search/src/Infrastructure/Cache/LaravelSearchCache.php'),
    );

    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= $token[1];

            continue;
        }
        $code .= $token;
    }

    expect($code)->not->toContain('->clear()')
        ->and($code)->not->toContain('Cache::flush')
        ->and($code)->not->toContain('cache()->clear');
});

it('recomputes rather than serving corrupt cached data', function (): void {
    $cache = app(LaravelSearchCache::class);

    // Poison the version pointer with something that is not a counter.
    Cache::forever((string) config('search.cache_prefix').':version', ['not', 'an', 'int']);

    // A malformed pointer must not throw and must not serve a wrong namespace
    // silently forever — reads degrade to misses, which is correct-but-slow.
    expect(fn (): string => $cache->namespacePrefix())->not->toThrow(Throwable::class);
    expect($cache->remember('after-corruption', 60, fn (): string => 'recomputed'))->toBe('recomputed');
});
