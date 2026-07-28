<?php

declare(strict_types=1);

namespace EruoFood\Support\Interface\Http\Controller;

use EruoFood\Support\Application\Service\AutomationRuleService;
use EruoFood\Support\Application\Service\SupportAnalyticsService;
use EruoFood\Support\Application\Service\SupportPresenter;
use EruoFood\Support\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Support\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The admin support portal: dashboards (queue, SLA, team, CSAT) and automation rules. */
final class SupportAdminController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly SupportAnalyticsService $analytics,
        private readonly AutomationRuleService $rules,
        private readonly SupportPresenter $presenter,
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->requireAgent($request);
        $days = $this->days($request);

        return $this->data([
            'queue' => $this->analytics->queue(),
            'sla' => $this->analytics->slaReport($days),
            'csat' => $this->analytics->csat($days)->toArray(),
        ]);
    }

    public function slaReport(Request $request): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data($this->analytics->slaReport($this->days($request)));
    }

    public function agentPerformance(Request $request): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data(['agents' => $this->analytics->agentPerformance($this->days($request))]);
    }

    public function csatReport(Request $request): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data($this->analytics->csat($this->days($request))->toArray());
    }

    // ---- Automation rules ------------------------------------------------

    public function listRules(Request $request): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data(['rules' => array_map(fn ($r): array => $this->presenter->rule($r), $this->rules->all())]);
    }

    public function createRule(Request $request): JsonResponse
    {
        $this->requireAgent($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', 'string', 'max:60'],
            'conditions' => ['nullable', 'array'],
            'actions' => ['required', 'array'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        /** @var list<array{field: string, op: string, value: string}> $conditions */
        $conditions = array_values((array) ($data['conditions'] ?? []));
        /** @var list<array{type: string, value?: string}> $actions */
        $actions = array_values((array) $data['actions']);

        $rule = $this->rules->create($data['name'], $data['trigger'], $conditions, $actions, (int) ($data['sort_order'] ?? 0));

        return $this->data($this->presenter->rule($rule), 201);
    }

    public function toggleRule(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return $this->data($this->presenter->rule($this->rules->setEnabled($id, (bool) $data['enabled'])));
    }

    public function deleteRule(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);
        $this->rules->delete($id);

        return new JsonResponse(null, 204);
    }

    private function days(Request $request): int
    {
        return max(1, min(365, (int) $request->query('days', '30')));
    }
}
