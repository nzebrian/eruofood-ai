<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Enum\Priority;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * The decoupling bridge: turns any published {@see DomainEvent} into a
 * notification, driven purely by the config event-map. It never imports another
 * context's event classes — it keys off the event's stable name and reads the
 * recipient id and data from the event's public properties. This is why no
 * business module ever calls the notification engine directly: they publish
 * events, and this translator reacts.
 *
 * Three capabilities beyond plain mapping, each earning its complexity:
 *
 * **Field allow-lists.** An entry may declare `fields`, and then *only* those
 * properties reach the template. Reflecting an entire event into template data
 * is fine for an order confirmation and unacceptable for identity verification,
 * where a future field added to an event would silently start appearing in
 * emails. Sensitive categories name what they need and get nothing else.
 *
 * **A standing deny-list.** Regardless of allow-lists, property names that carry
 * regulated data never reach a template. Belt and braces: the allow-list is the
 * control, and this catches the case where somebody adds an entry without one.
 *
 * **Conditional entries.** An event name may map to several entries, each with a
 * `when` clause matched against the event's properties, so one
 * `subject_verified` event can produce a rider email and a merchant email with
 * different wording — without the publisher needing to know that notifications
 * exist.
 *
 * @phpstan-type MapEntry array{category: string, template: string, channels: list<string>, recipient: list<string>, fields?: list<string>, when?: array<string, string>, priority?: string, correlation?: string, data?: array<string, scalar>}
 */
final readonly class EventTranslator
{
    /**
     * Property names that never reach a template, whatever the map says.
     *
     * An email is stored by the recipient's provider, indexed, backed up and
     * frequently forwarded. None of these belong in one — and a notification
     * that needs them is a notification that should say "sign in to see this"
     * instead.
     */
    private const NEVER_EXPOSE = [
        'documentnumber', 'document_number', 'numberlast4', 'number_last4',
        'registrationnumber', 'registration_number', 'nationalid', 'national_id',
        'passportnumber', 'passport_number', 'licencenumber', 'licence_number',
        'licensenumber', 'license_number', 'bvn', 'nin', 'taxid', 'tax_id',
        'dateofbirth', 'date_of_birth', 'dob',
        'providerreference', 'provider_reference', 'sessionid', 'session_id',
        'rawpayload', 'raw_payload', 'payload', 'secret', 'token', 'apikey', 'api_key',
        'signature', 'password', 'codehash', 'code_hash', 'code',
        'address', 'phone', 'phonenumber', 'phone_number',
    ];

    /**
     * @param array<string, mixed> $eventMap
     */
    public function __construct(
        private NotificationService $notifications,
        private array $eventMap,
    ) {
    }

    public function handle(DomainEvent $event): void
    {
        $entries = $this->entriesFor($event->eventName());
        if ($entries === []) {
            return; // no mapping — not a notifying event
        }

        $vars = $this->publicVars($event);

        foreach ($entries as $entry) {
            if (! $this->matches($entry, $vars)) {
                continue;
            }

            $recipient = $this->resolveRecipient($vars, $entry['recipient'] ?? []);
            if ($recipient === null) {
                continue; // event carries no addressable recipient
            }

            $channels = array_values(array_filter(array_map(
                static fn (string $c): ?NotificationChannel => NotificationChannel::tryFrom($c),
                $entry['channels'] ?? [],
            )));

            $category = NotificationCategory::tryFrom((string) ($entry['category'] ?? ''));
            if ($category === null || $channels === []) {
                continue;
            }

            $this->notifications->notify(
                userId: $recipient,
                category: $category,
                templateKey: (string) $entry['template'],
                data: $this->templateData($entry, $vars, $event->eventName()),
                channels: $channels,
                priority: Priority::tryFrom((string) ($entry['priority'] ?? '')) ?? Priority::Normal,
                correlationId: $this->correlationId($entry, $vars),
            );
        }
    }

    /**
     * A map value may be one entry or a list of them.
     *
     * @return list<array<string, mixed>>
     */
    private function entriesFor(string $eventName): array
    {
        $value = $this->eventMap[$eventName] ?? null;
        if (! is_array($value) || $value === []) {
            return [];
        }

        // A list of entries, versus a single associative entry.
        if (array_is_list($value)) {
            /** @var list<array<string, mixed>> $value */
            return array_values(array_filter($value, 'is_array'));
        }

        /** @var array<string, mixed> $value */
        return [$value];
    }

    /**
     * Whether a conditional entry applies to this event instance.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $vars
     */
    private function matches(array $entry, array $vars): bool
    {
        $when = $entry['when'] ?? null;
        if (! is_array($when)) {
            return true;
        }

        foreach ($when as $property => $expected) {
            $actual = $vars[$property] ?? null;
            if (! is_scalar($actual) || (string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * The data a template may see.
     *
     * With an allow-list, exactly those properties and nothing else. Without
     * one, the historical behaviour — every public property — minus the standing
     * deny-list.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function templateData(array $entry, array $vars, string $eventName): array
    {
        $allowed = $entry['fields'] ?? null;

        if (is_array($allowed)) {
            $selected = [];
            foreach ($allowed as $field) {
                $field = (string) $field;
                if (array_key_exists($field, $vars)) {
                    $selected[$field] = $vars[$field];
                }
            }
        } else {
            $selected = $vars;
        }

        $static = is_array($entry['data'] ?? null) ? $entry['data'] : [];

        return $this->redact($this->snakeKeys($selected)) + $static + ['event' => $eventName];
    }

    /**
     * Strip anything whose name marks it as regulated, whatever route it took
     * to get here.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach (array_keys($data) as $key) {
            $normalised = strtolower(str_replace(['-', ' '], '_', (string) $key));

            if (in_array($normalised, self::NEVER_EXPOSE, true)
                || in_array(str_replace('_', '', $normalised), self::NEVER_EXPOSE, true)) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $vars
     */
    private function correlationId(array $entry, array $vars): ?string
    {
        $key = $entry['correlation'] ?? null;
        if (! is_string($key)) {
            return null;
        }

        $value = $vars[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
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
            $snake = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $key));
            $out[$snake] = $value;
        }

        return $out;
    }
}
