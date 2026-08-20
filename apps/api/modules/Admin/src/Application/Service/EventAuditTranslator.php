<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Service;

use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * The decoupling bridge for Audit & Compliance: turns published {@see DomainEvent}s
 * from any context into audit entries, driven purely by the config event-map. It
 * never imports another context's event classes — it keys off the event's stable
 * name and reads the subject id + data from the event's public properties via
 * reflection (`get_object_vars`). This is how security/login/config events from
 * across the platform land in one compliance trail without coupling.
 */
final readonly class EventAuditTranslator
{
    /**
     * @param array<string, string> $eventMap external event name => audit action
     */
    public function __construct(
        private AuditService $audit,
        private array $eventMap,
    ) {
    }

    public function handle(DomainEvent $event): void
    {
        $action = $this->eventMap[$event->eventName()] ?? null;
        if ($action === null) {
            return; // not an audited event
        }

        $vars = $this->scalarVars($event);
        [$subjectType, $subjectId] = $this->subjectOf($vars);

        // An event may name the specific action it represents. The map still
        // decides *whether* an event is audited — an unmapped event is ignored
        // exactly as before — but a context that emits many distinct privileged
        // actions through one event class can say which one this was, instead of
        // needing a separate class and map entry per action.
        $action = $this->firstOf($vars, ['auditAction', 'audit_action']) ?? $action;

        $this->audit->record(
            // Most published events are system-originated and carry no actor.
            // Some — a privileged read of identity data, for one — name the
            // person responsible, and an audit entry that discards that name is
            // useless for the review it exists to serve.
            actorId: $this->firstOf($vars, ['actorId', 'actor_id']),
            category: $this->categoryFor($action),
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            context: ['event' => $event->eventName()] + $vars,
        );
    }

    /**
     * Which entity the event is about, by convention over the event's property
     * names. Still no imports: the translator recognises the shape of an id,
     * not the class it came from.
     *
     * @param array<string, scalar|null> $vars
     * @return array{string|null, string|null}
     */
    private function subjectOf(array $vars): array
    {
        // An event that states its own subject type is believed. Without this,
        // anything not in the list below was recorded as type "external", which
        // makes a compliance query for "every action against settlement run X"
        // impossible to write.
        $declaredType = $this->firstOf($vars, ['subjectType', 'subject_type']);
        $declaredId = $this->firstOf($vars, ['subjectId', 'subject_id']);
        if ($declaredType !== null && $declaredId !== null) {
            return [$declaredType, $declaredId];
        }

        $byType = [
            'verification_case' => ['caseId', 'case_id'],
            'user' => ['userId', 'user_id'],
            'vendor' => ['vendorId', 'vendor_id'],
            'payment' => ['paymentId', 'payment_id'],
        ];

        foreach ($byType as $type => $keys) {
            $id = $this->firstOf($vars, $keys);
            if ($id !== null) {
                return [$type, $id];
            }
        }

        $fallback = $this->firstOf($vars, ['subjectId', 'subject_id', 'id']);

        return $fallback === null ? [null, null] : ['external', $fallback];
    }

    private function categoryFor(string $action): AuditCategory
    {
        $prefix = explode('.', $action)[0];

        return match ($prefix) {
            'security' => AuditCategory::Security,
            'config' => AuditCategory::Config,
            'ops' => AuditCategory::Operations,
            'content' => AuditCategory::Content,
            'support' => AuditCategory::Support,
            'finance' => AuditCategory::Finance,
            default => AuditCategory::Users,
        };
    }

    /**
     * Public scalar properties of the event (drops occurredAt and any non-scalar).
     *
     * @return array<string, scalar|null>
     */
    private function scalarVars(DomainEvent $event): array
    {
        /** @var array<string, mixed> $vars */
        $vars = get_object_vars($event);
        unset($vars['occurredAt']);

        $out = [];
        foreach ($vars as $key => $value) {
            if ($value === null || is_scalar($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, scalar|null> $vars
     * @param list<string> $keys
     */
    private function firstOf(array $vars, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($vars[$key]) && is_string($vars[$key]) && $vars[$key] !== '') {
                return $vars[$key];
            }
        }

        return null;
    }
}
