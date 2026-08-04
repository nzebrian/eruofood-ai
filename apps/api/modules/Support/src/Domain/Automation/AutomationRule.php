<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Automation;

/**
 * A declarative support automation rule: when a `trigger` fires, if every
 * `condition` matches the event context, apply each `action`. Kept framework-free
 * so the matching logic is unit-testable; the AutomationEngine (application)
 * evaluates rules and executes the resulting actions against the ticket.
 *
 * Conditions and actions are simple key/value maps so rules are data (editable in
 * the admin portal) rather than code:
 *   condition {field: "priority", op: "eq", value: "urgent"}
 *   action    {type: "assign", value: "<agentId>"} | {type:"set_priority"} | {type:"add_tag"} | {type:"escalate"} | {type:"reply_template"}
 */
final class AutomationRule
{
    /**
     * @param list<array{field: string, op: string, value: string}> $conditions
     * @param list<array{type: string, value?: string}> $actions
     */
    private function __construct(
        private readonly string $id,
        private string $name,
        private string $trigger,
        private array $conditions,
        private array $actions,
        private bool $enabled,
        private int $sortOrder,
    ) {
    }

    /**
     * @param list<array{field: string, op: string, value: string}> $conditions
     * @param list<array{type: string, value?: string}> $actions
     */
    public static function create(string $id, string $name, string $trigger, array $conditions, array $actions, int $sortOrder): self
    {
        return new self($id, $name, $trigger, $conditions, $actions, true, $sortOrder);
    }

    /**
     * @param list<array{field: string, op: string, value: string}> $conditions
     * @param list<array{type: string, value?: string}> $actions
     */
    public static function reconstitute(string $id, string $name, string $trigger, array $conditions, array $actions, bool $enabled, int $sortOrder): self
    {
        return new self($id, $name, $trigger, $conditions, $actions, $enabled, $sortOrder);
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Whether this rule fires for a trigger given the event context.
     *
     * @param array<string, scalar|null> $context
     */
    public function matches(string $trigger, array $context): bool
    {
        if (! $this->enabled || $this->trigger !== $trigger) {
            return false;
        }
        foreach ($this->conditions as $condition) {
            if (! $this->conditionHolds($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{field: string, op: string, value: string} $condition
     * @param array<string, scalar|null> $context
     */
    private function conditionHolds(array $condition, array $context): bool
    {
        $actual = $context[$condition['field']] ?? null;
        $expected = $condition['value'];

        return match ($condition['op']) {
            'eq' => (string) $actual === $expected,
            'neq' => (string) $actual !== $expected,
            'contains' => $actual !== null && str_contains(mb_strtolower((string) $actual), mb_strtolower($expected)),
            'gte' => is_numeric($actual) && (float) $actual >= (float) $expected,
            'lte' => is_numeric($actual) && (float) $actual <= (float) $expected,
            default => false,
        };
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function trigger(): string
    {
        return $this->trigger;
    }

    /** @return list<array{field: string, op: string, value: string}> */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /** @return list<array{type: string, value?: string}> */
    public function actions(): array
    {
        return $this->actions;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
