<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Template;

use EruoFood\Notifications\Domain\Enum\NotificationChannel;

/** Persistence port for {@see NotificationTemplate}. */
interface NotificationTemplateRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?NotificationTemplate;

    public function find(string $key, NotificationChannel $channel, string $locale): ?NotificationTemplate;

    /** @return list<NotificationTemplate> */
    public function all(): array;

    public function save(NotificationTemplate $template): void;
}
