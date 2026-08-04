<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Service;

use DateTimeImmutable;
use EruoFood\Support\Domain\Automation\AutomationRule;
use EruoFood\Support\Domain\Automation\AutomationRuleRepository;
use EruoFood\Support\Domain\Enum\TicketPriority;
use EruoFood\Support\Domain\Ticket\Ticket;
use EruoFood\Support\Domain\Ticket\TicketRepository;

/**
 * The automation rules engine: for a trigger and event context it finds the
 * matching rules (in sort order) and applies their actions to the ticket —
 * auto-routing (assign), priority changes, tagging, escalation and templated
 * replies. It mutates the ticket in place and reports whether the priority
 * changed, so the caller can re-apply the SLA policy. Rules are data, so
 * behaviour is editable in the admin portal without a deploy.
 */
final readonly class AutomationEngine
{
    public function __construct(
        private AutomationRuleRepository $rules,
        private TicketRepository $tickets,
    ) {
    }

    /**
     * Apply all matching rules for a trigger. Returns true if the ticket's
     * priority changed (caller should re-apply SLA).
     *
     * @param array<string, scalar|null> $context
     */
    public function run(string $trigger, array $context, Ticket $ticket, DateTimeImmutable $now): bool
    {
        $priorityChanged = false;

        foreach ($this->rules->forTrigger($trigger) as $rule) {
            if (! $rule->matches($trigger, $context)) {
                continue;
            }
            if ($this->apply($rule, $ticket, $now)) {
                $priorityChanged = true;
            }
        }

        return $priorityChanged;
    }

    private function apply(AutomationRule $rule, Ticket $ticket, DateTimeImmutable $now): bool
    {
        $priorityChanged = false;

        foreach ($rule->actions() as $action) {
            $value = $action['value'] ?? null;
            switch ($action['type']) {
                case 'assign':
                    if (is_string($value) && $value !== '' && $ticket->assigneeId() === null) {
                        $ticket->assign($value, $now);
                    }
                    break;
                case 'set_priority':
                    $priority = is_string($value) ? TicketPriority::tryFrom($value) : null;
                    if ($priority !== null) {
                        $ticket->changePriority($priority, $now);
                        $priorityChanged = true;
                    }
                    break;
                case 'escalate':
                    $ticket->escalate($now);
                    $priorityChanged = true;
                    break;
                case 'add_tag':
                    if (is_string($value) && $value !== '') {
                        $ticket->addTag($value, $now);
                    }
                    break;
                case 'add_note':
                case 'reply_template':
                    if (is_string($value) && $value !== '') {
                        $ticket->addSystemNote($this->tickets->nextMessageIdentity(), $value, $now);
                    }
                    break;
            }
        }

        return $priorityChanged;
    }
}
