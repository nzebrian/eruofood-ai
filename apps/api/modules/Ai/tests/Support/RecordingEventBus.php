<?php

declare(strict_types=1);

namespace EruoFood\Ai\Tests\Support;

use EruoFood\Shared\Domain\DomainEvent;
use EruoFood\Shared\Domain\EventBus;

/** Captures published domain events for assertions. */
final class RecordingEventBus implements EventBus
{
    /** @var list<DomainEvent> */
    public array $published = [];

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->published[] = $event;
        }
    }
}
