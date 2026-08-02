<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Event;

use EruoFood\PublicApi\Application\Service\WebhookService;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Bridges internal domain events to the public webhook system. For every
 * internal event named in the config `webhooks.events` map it registers a
 * listener that, when the event fires, fans it out (by its public name) to
 * subscribed webhooks. It reads the event's public properties reflectively and
 * derives a stable delivery id for idempotency — it never imports another
 * context's event class.
 */
final readonly class DomainEventSubscriber
{
    /**
     * @param array<string, string> $eventMap internal event name => public event name
     */
    public function __construct(
        private Dispatcher $dispatcher,
        private array $eventMap,
    ) {
    }

    public function register(): void
    {
        foreach ($this->eventMap as $internalName => $publicName) {
            $public = (string) $publicName;
            $this->dispatcher->listen($internalName, function (object $event) use ($public): void {
                /** @var array<string, mixed> $vars */
                $vars = get_object_vars($event);
                unset($vars['occurredAt']);

                $data = array_map(
                    static fn (mixed $v): mixed => is_scalar($v) || $v === null || is_array($v) ? $v : (string) $v,
                    $vars,
                );
                $eventId = hash('sha256', $public.'|'.json_encode($data, JSON_UNESCAPED_SLASHES));

                app(WebhookService::class)->dispatchEvent($public, $eventId, $data);
            });
        }
    }
}
