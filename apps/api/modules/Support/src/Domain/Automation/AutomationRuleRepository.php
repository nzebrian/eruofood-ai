<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Automation;

/** Persistence port for {@see AutomationRule}. */
interface AutomationRuleRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?AutomationRule;

    /**
     * Enabled rules for a trigger, in evaluation (sort) order.
     *
     * @return list<AutomationRule>
     */
    public function forTrigger(string $trigger): array;

    /** @return list<AutomationRule> */
    public function all(): array;

    public function save(AutomationRule $rule): void;

    public function delete(string $id): void;
}
