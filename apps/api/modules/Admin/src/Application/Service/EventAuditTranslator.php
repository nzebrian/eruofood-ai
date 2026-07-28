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
     * @param array<string, string> $eventMap  external event name => audit action
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
        $subjectId = $this->firstOf($vars, ['userId', 'user_id', 'vendorId', 'vendor_id', 'paymentId', 'payment_id', 'id']);

        $this->audit->record(
            actorId: null, // system-originated: no admin actor
            category: $this->categoryFor($action),
            action: $action,
            subjectType: $subjectId === null ? null : 'external',
            subjectId: $subjectId,
            context: ['event' => $event->eventName()] + $vars,
        );
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
