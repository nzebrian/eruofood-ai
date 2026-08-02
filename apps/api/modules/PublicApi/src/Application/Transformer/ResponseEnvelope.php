<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Transformer;

use EruoFood\Shared\Domain\Paginated;

/**
 * The single source of the public API's standard response shapes. Every public
 * endpoint returns one of these envelopes so external clients can rely on a
 * stable contract independent of any internal representation:
 *
 *   success:    { "data": ..., "meta": { ... } }
 *   collection: { "data": [ ... ], "meta": { pagination + request } }
 *   error:      { "error": { "code", "message", "details" } }
 */
final readonly class ResponseEnvelope
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    public function item(array $data, array $meta = []): array
    {
        return ['data' => $data, 'meta' => (object) $meta];
    }

    /**
     * @template T
     * @param Paginated<T> $page
     * @param callable(T): array<string, mixed> $transform
     *
     * @return array<string, mixed>
     */
    public function collection(Paginated $page, callable $transform, string $version): array
    {
        $lastPage = $page->perPage > 0 ? (int) ceil($page->total / $page->perPage) : 1;

        return [
            'data' => array_map($transform, $page->items),
            'meta' => [
                'pagination' => [
                    'page' => $page->page,
                    'per_page' => $page->perPage,
                    'total' => $page->total,
                    'last_page' => max(1, $lastPage),
                    'has_more' => $page->page < $lastPage,
                ],
                'version' => $version,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    public function error(string $code, string $message, array $details = []): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return ['error' => $error];
    }
}
