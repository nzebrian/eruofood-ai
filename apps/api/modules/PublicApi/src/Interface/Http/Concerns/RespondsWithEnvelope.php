<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Concerns;

use EruoFood\PublicApi\Application\Transformer\ResponseEnvelope;
use EruoFood\PublicApi\Domain\Read\ResourceQuery;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Standard public-API response + query helpers: the success/collection envelope
 * and a parser that turns raw query strings into a normalised {@see ResourceQuery}
 * (pagination clamped to the configured maximum; standard `q`, `sort`, `filter[]`).
 */
trait RespondsWithEnvelope
{
    private function envelope(): ResponseEnvelope
    {
        return new ResponseEnvelope();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    protected function item(array $data, array $meta = [], int $status = 200): JsonResponse
    {
        return new JsonResponse($this->envelope()->item($data, $meta), $status);
    }

    /**
     * @template T
     * @param Paginated<T> $page
     * @param callable(T): array<string, mixed> $transform
     */
    protected function collection(Paginated $page, callable $transform): JsonResponse
    {
        $version = (string) config('publicapi.current_version', 'v1');

        return new JsonResponse($this->envelope()->collection($page, $transform, $version));
    }

    protected function resourceQuery(Request $request): ResourceQuery
    {
        $max = (int) config('publicapi.pagination.max_per_page', 100);
        $default = (int) config('publicapi.pagination.default_per_page', 20);

        $perPage = (int) $request->query('per_page', (string) $default);
        $perPage = max(1, min($max, $perPage));

        /** @var array<string, string> $filters */
        $filters = is_array($request->query('filter')) ? array_map('strval', $request->query('filter')) : [];
        $search = $request->query('q');
        $sort = $request->query('sort');

        return new ResourceQuery(
            page: max(1, (int) $request->query('page', '1')),
            perPage: $perPage,
            search: is_string($search) && $search !== '' ? $search : null,
            sort: is_string($sort) && $sort !== '' ? $sort : null,
            filters: $filters,
        );
    }
}
