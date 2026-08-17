<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller\Admin;

use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Application\Service\ReconciliationCaseService;
use EruoFood\Payments\Domain\Enum\ReconciliationState;
use EruoFood\Payments\Domain\Settlement\ReconciliationCase;
use EruoFood\Payments\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The discrepancy queue.
 *
 * Investigating and escalating sit behind `finance.reconcile`; resolving a case
 * by adjustment sits behind `finance.adjust`, which is SuperAdmin only. That
 * split is the point of the controller: looking into a disagreement is ordinary
 * finance work, and deciding the books were wrong is not.
 */
final readonly class ReconciliationAdminController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private ReconciliationCaseService $cases,
        private PaymentsPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $state = $request->filled('state')
            ? ReconciliationState::tryFrom((string) $request->string('state'))
            : null;

        $page = $this->cases->list($state, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (ReconciliationCase $c): array => $this->presenter->reconciliationCase($c));
    }

    public function show(string $id): JsonResponse
    {
        return $this->data($this->presenter->reconciliationCase($this->cases->get($id)));
    }

    public function investigate(Request $request, string $id): JsonResponse
    {
        return $this->data($this->presenter->reconciliationCase(
            $this->cases->investigate($this->currentUserId($request), $id),
        ));
    }

    public function escalate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->data($this->presenter->reconciliationCase(
            $this->cases->escalate($this->currentUserId($request), $id, (string) $data['note']),
        ));
    }

    /**
     * Close a case.
     *
     * Two shapes: `matched` says the two sides agree after all and needs only a
     * note; `adjusted` says the books were wrong and requires the id of a
     * compensating ledger posting that already exists. There is no third shape,
     * and in particular no way to close a case by asserting it is fine.
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'in:matched,adjusted'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
            'compensating_posting_id' => ['required_if:resolution,adjusted', 'string', 'max:64'],
        ]);

        $actor = $this->currentUserId($request);

        $case = $data['resolution'] === 'adjusted'
            ? $this->cases->resolveAdjusted($actor, $id, (string) $data['compensating_posting_id'], (string) $data['note'])
            : $this->cases->resolveMatched($actor, $id, (string) $data['note']);

        return $this->data($this->presenter->reconciliationCase($case));
    }
}
