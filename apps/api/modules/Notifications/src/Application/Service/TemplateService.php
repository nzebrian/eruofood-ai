<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Exception\NotificationsNotFound;
use EruoFood\Notifications\Domain\Template\NotificationTemplate;
use EruoFood\Notifications\Domain\Template\NotificationTemplateRepository;

/** Notification template management for the admin portal. */
final readonly class TemplateService
{
    public function __construct(private NotificationTemplateRepository $templates)
    {
    }

    /** @return list<NotificationTemplate> */
    public function all(): array
    {
        return $this->templates->all();
    }

    public function upsert(string $key, NotificationChannel $channel, string $locale, string $subject, string $body): NotificationTemplate
    {
        $existing = $this->templates->find($key, $channel, $locale);
        if ($existing !== null) {
            $existing->update($subject, $body);
            $this->templates->save($existing);

            return $existing;
        }
        $template = NotificationTemplate::create($this->templates->nextIdentity(), $key, $channel, $locale, $subject, $body);
        $this->templates->save($template);

        return $template;
    }

    public function get(string $id): NotificationTemplate
    {
        return $this->templates->findById($id) ?? throw NotificationsNotFound::of('template', $id);
    }
}
