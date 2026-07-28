<?php

declare(strict_types=1);

namespace EruoFood\Support\Interface\Http\Controller;

use EruoFood\Support\Application\Service\CrmService;
use EruoFood\Support\Application\Service\SupportPresenter;
use EruoFood\Support\Domain\Crm\CustomerSegment;
use EruoFood\Support\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Support\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The CRM dashboard: customer profiles, timelines, insights and segmentation (agent). */
final class CrmController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly CrmService $crm,
        private readonly SupportPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->requireAgent($request);
        $term = $request->query('q');
        $segment = $request->query('segment');

        return $this->paginated(
            $this->crm->search(
                is_string($term) ? $term : null,
                is_string($segment) ? CustomerSegment::tryFrom($segment) : null,
                (int) $request->query('page', '1'),
                (int) $request->query('per_page', '20'),
            ),
            fn ($p): array => $this->presenter->profile($p),
        );
    }

    public function show(Request $request, string $userId): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data($this->presenter->profile($this->crm->getOrCreate($userId)));
    }

    public function timeline(Request $request, string $userId): JsonResponse
    {
        $this->requireAgent($request);

        return $this->paginated(
            $this->crm->timeline($userId, (int) $request->query('page', '1'), (int) $request->query('per_page', '30')),
            fn ($i): array => $this->presenter->interaction($i),
        );
    }

    public function tag(Request $request, string $userId): JsonResponse
    {
        $this->requireAgent($request);
        $data = $request->validate(['tag' => ['required', 'string', 'max:60']]);

        return $this->data($this->presenter->profile($this->crm->addTag($userId, $data['tag'])));
    }

    public function notes(Request $request, string $userId): JsonResponse
    {
        $this->requireAgent($request);
        $data = $request->validate(['notes' => ['required', 'string', 'max:5000']]);

        return $this->data($this->presenter->profile($this->crm->setNotes($userId, $data['notes'])));
    }

    public function insight(Request $request, string $userId): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data($this->presenter->profile($this->crm->generateInsight($userId)));
    }

    public function segments(Request $request): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data(['segments' => $this->crm->segmentCounts()]);
    }
}
