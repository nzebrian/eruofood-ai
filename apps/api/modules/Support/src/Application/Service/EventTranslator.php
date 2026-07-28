<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Service;

use EruoFood\Shared\Domain\DomainEvent;

/**
 * The decoupling bridge for the CRM timeline: folds published {@see DomainEvent}s
 * from any context (registration, orders, payments) into the customer's profile
 * and interaction timeline, driven purely by the config event-map. It never
 * imports another context's event classes — it keys off the event's stable name
 * and reads the user id / amount / reference from the event's public properties
 * via reflection. This is how the CRM is built without any module knowing Support
 * exists.
 */
final readonly class EventTranslator
{
    /**
     * @param array<string, string> $eventMap  external event name => interaction kind
     */
    public function __construct(
        private CrmService $crm,
        private array $eventMap,
    ) {
    }

    public function handle(DomainEvent $event): void
    {
        $kind = $this->eventMap[$event->eventName()] ?? null;
        if ($kind === null) {
            return;
        }

        /** @var array<string, mixed> $vars */
        $vars = get_object_vars($event);
        $userId = $this->firstString($vars, ['userId', 'user_id', 'customerUserId', 'payerUserId', 'requesterId']);
        if ($userId === null) {
            return;
        }

        $ref = $this->firstString($vars, ['orderId', 'paymentId', 'id']);
        $amount = $this->firstInt($vars, ['amountMinor', 'amount_minor', 'totalMinor', 'totalMinorUnits', 'grossMinor']);
        $summary = $this->summaryFor($kind, $vars);

        $this->crm->onExternalEvent($kind, $userId, $summary, $ref, $amount);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function summaryFor(string $kind, array $vars): string
    {
        return match ($kind) {
            'account.registered' => 'Account created',
            'order.placed' => 'Placed an order',
            'payment.succeeded' => 'Payment succeeded',
            'payment.failed' => 'Payment failed',
            default => ucfirst(str_replace(['.', '_'], ' ', $kind)),
        };
    }

    /**
     * @param array<string, mixed> $vars
     * @param list<string> $keys
     */
    private function firstString(array $vars, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $vars[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_object($value) && method_exists($value, '__toString')) {
                $string = (string) $value;
                if ($string !== '') {
                    return $string;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $vars
     * @param list<string> $keys
     */
    private function firstInt(array $vars, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($vars[$key]) && is_numeric($vars[$key])) {
                return (int) $vars[$key];
            }
        }

        return null;
    }
}
