<?php

declare(strict_types=1);

namespace EruoFood\Geo\Interface\Http\Concerns;

use Illuminate\Http\JsonResponse;

/** Envelope helpers used by Geo controllers. */
trait RespondsWithData
{
    /** @param array<string, mixed>|list<mixed> $data */
    protected function data(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }
}
