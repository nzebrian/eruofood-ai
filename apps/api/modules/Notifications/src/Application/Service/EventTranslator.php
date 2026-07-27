<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * The decoupling bridge: turns any published {@see DomainEvent} into a
 * notification, driven purely by the config event-map. It never imports another
 * context's event classes — it keys off the event's stable name and reads the
 * recipient id and data from the event's public properties via reflection
 * (`get_object_vars`). This is why no business module ever calls the
 * notification engine directly: they publish events, and this translator reacts.
 *
 * @phpstan-type MapEntry array{category: string, template: string, channels: list<string>, recipient: list<string>}
 */
final readonly class EventTranslator
{
    /**
     * @param array<string, array{category: string, template: string, channels: list<string>, recipient: list<string>}> $eventMap
     */
    public function __construct(
        private NotificationService $notifications,
        private array $eventMap,
    ) {
    }

    public function handle(DomainEvent $event): void
    {
        $entry = $this->eventMap[$event->eventName()] ?? null;
        if ($entry === null) {
            return; // no mapping — not a notifying event
        }

        $vars = $this->publicVars($event);
        $recipient = $this->resolveRecipient($vars, $entry['recipient']);
        if ($recipient === null) {
            return; // event carries no addressable recipient
        }

        $channels = array_values(array_filter(array_map(
            static fn (string $c): ?NotificationChannel => NotificationChannel::tryFrom($c),
            $entry['channels'],
        )));

        $this->notifications->notify(
            userId: $recipient,
            category: NotificationCategory::from($entry['category']),
            templateKey: $entry['template'],
            data: $this->snakeKeys($vars) + ['event' => $event->eventName()],
            channels: $channels,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function publicVars(DomainEvent $event): array
    {
        /** @var array<string, mixed> $vars */
        $vars = get_object_vars($event);
        unset($vars['occurredAt']);

        return $vars;
    }

    /**
     * @param array<string, mixed> $vars
     * @param list<string> $keys
     */
    private function resolveRecipient(array $vars, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($vars[$key]) && is_string($vars[$key]) && $vars[$key] !== '') {
                return $vars[$key];
            }
        }

        return null;
    }

    /**
     * Expose camelCase event props under snake_case keys too, so templates can
     * use {{ amount_minor }} regardless of the event's PHP naming.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function snakeKeys(array $vars): array
    {
        $out = $vars;
        foreach ($vars as $key => $value) {
            $snake = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
            $out[$snake] = $value;
        }

        return $out;
    }
}
