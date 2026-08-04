<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent;

use EruoFood\Support\Domain\Automation\AutomationRule;
use EruoFood\Support\Domain\Automation\AutomationRuleRepository;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\Model\AutomationRuleModel;
use Illuminate\Support\Str;

final class EloquentAutomationRuleRepository implements AutomationRuleRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?AutomationRule
    {
        $m = AutomationRuleModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forTrigger(string $trigger): array
    {
        return array_values(array_map(
            fn (AutomationRuleModel $m): AutomationRule => $this->toDomain($m),
            AutomationRuleModel::query()->where('trigger', $trigger)->where('enabled', true)
                ->orderBy('sort_order')->get()->all(),
        ));
    }

    public function all(): array
    {
        return array_values(array_map(
            fn (AutomationRuleModel $m): AutomationRule => $this->toDomain($m),
            AutomationRuleModel::query()->orderBy('trigger')->orderBy('sort_order')->get()->all(),
        ));
    }

    public function save(AutomationRule $rule): void
    {
        $model = AutomationRuleModel::query()->find($rule->id()) ?? new AutomationRuleModel();
        $model->id = $rule->id();
        $model->name = $rule->name();
        $model->trigger = $rule->trigger();
        $model->conditions = $rule->conditions();
        $model->actions = $rule->actions();
        $model->enabled = $rule->isEnabled();
        $model->sort_order = $rule->sortOrder();
        $model->save();
    }

    public function delete(string $id): void
    {
        AutomationRuleModel::query()->whereKey($id)->delete();
    }

    private function toDomain(AutomationRuleModel $m): AutomationRule
    {
        /** @var list<array{field: string, op: string, value: string}> $conditions */
        $conditions = $m->conditions ?? [];
        /** @var list<array{type: string, value?: string}> $actions */
        $actions = $m->actions ?? [];

        return AutomationRule::reconstitute(
            $m->id,
            $m->name,
            $m->trigger,
            $conditions,
            $actions,
            (bool) $m->enabled,
            (int) $m->sort_order,
        );
    }
}
