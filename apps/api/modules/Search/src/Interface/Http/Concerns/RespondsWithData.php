<?php

declare(strict_types=1);

namespace EruoFood\Search\Interface\Http\Concerns;

use Illuminate\Http\JsonResponse;

/** Envelope helper used by Search controllers. */
trait RespondsWithData
{
    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    protected function data(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }
}
