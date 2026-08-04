<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Service;

use EruoFood\Support\Domain\Automation\AutomationRule;
use EruoFood\Support\Domain\Automation\AutomationRuleRepository;
use EruoFood\Support\Domain\Exception\SupportNotFound;

/** Admin management of automation rules (the automation-rules panel). */
final readonly class AutomationRuleService
{
    public function __construct(
        private AutomationRuleRepository $rules,
    ) {
    }

    /**
     * @param list<array{field: string, op: string, value: string}> $conditions
     * @param list<array{type: string, value?: string}> $actions
     */
    public function create(string $name, string $trigger, array $conditions, array $actions, int $sortOrder): AutomationRule
    {
        $rule = AutomationRule::create($this->rules->nextIdentity(), $name, $trigger, $conditions, $actions, $sortOrder);
        $this->rules->save($rule);

        return $rule;
    }

    public function setEnabled(string $id, bool $enabled): AutomationRule
    {
        $rule = $this->rules->findById($id) ?? throw SupportNotFound::of('automation rule', $id);
        $enabled ? $rule->enable() : $rule->disable();
        $this->rules->save($rule);

        return $rule;
    }

    public function delete(string $id): void
    {
        $this->rules->findById($id) ?? throw SupportNotFound::of('automation rule', $id);
        $this->rules->delete($id);
    }

    /** @return list<AutomationRule> */
    public function all(): array
    {
        return $this->rules->all();
    }
}
