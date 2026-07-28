<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Interface\Http\Concerns;

use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\ValueObject\Subject;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Http\JsonResponse;

/** Envelope + subject-parsing helpers used by Reviews controllers. */
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

    protected function subject(string $subjectType, string $subjectId): Subject
    {
        $type = SubjectType::tryFrom($subjectType)
            ?? throw new \EruoFood\Reviews\Domain\Exception\ReviewsInvalidState('Unknown subject type: '.$subjectType);

        return new Subject($type, $subjectId);
    }
}
