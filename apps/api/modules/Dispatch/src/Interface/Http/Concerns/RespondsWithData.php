<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Interface\Http\Concerns;

use Illuminate\Http\JsonResponse;

/** Envelope helpers used by Dispatch controllers. */
trait RespondsWithData
{
    /** @param array<string, mixed>|list<mixed> $data */
    protected function data(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * @param list<mixed> $items
     * @param array<string, mixed> $meta
     */
    protected function collection(array $items, array $meta = []): JsonResponse
    {
        return new JsonResponse($meta === [] ? ['data' => $items] : ['data' => $items, 'meta' => $meta]);
    }
}
