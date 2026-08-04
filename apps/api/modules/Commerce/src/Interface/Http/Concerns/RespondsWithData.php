<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Concerns;

use EruoFood\Shared\Domain\Paginated;
use Illuminate\Http\JsonResponse;

/** Envelope helpers used by Commerce controllers. */
trait RespondsWithData
{
    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    protected function data(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * @template T
     * @param Paginated<T> $page
     * @param callable(T): array<string, mixed> $mapper
     */
    protected function paginated(Paginated $page, callable $mapper): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map($mapper, $page->items),
            'meta' => ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total],
        ]);
    }
}
