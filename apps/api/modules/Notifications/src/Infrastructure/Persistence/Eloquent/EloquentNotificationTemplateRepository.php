<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent;

use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Template\NotificationTemplate;
use EruoFood\Notifications\Domain\Template\NotificationTemplateRepository;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model\NotificationTemplateModel;
use Illuminate\Support\Str;

final class EloquentNotificationTemplateRepository implements NotificationTemplateRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?NotificationTemplate
    {
        $m = NotificationTemplateModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function find(string $key, NotificationChannel $channel, string $locale): ?NotificationTemplate
    {
        $m = NotificationTemplateModel::query()
            ->where('key', $key)->where('channel', $channel->value)->where('locale', $locale)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(): array
    {
        return array_values(array_map(
            fn (NotificationTemplateModel $m): NotificationTemplate => $this->toDomain($m),
            NotificationTemplateModel::query()->orderBy('key')->get()->all(),
        ));
    }

    public function save(NotificationTemplate $template): void
    {
        $model = NotificationTemplateModel::query()->find($template->id()) ?? new NotificationTemplateModel();
        $model->id = $template->id();
        $model->key = $template->key();
        $model->channel = $template->channel()->value;
        $model->locale = $template->locale();
        $model->subject = $template->subject();
        $model->body = $template->body();
        $model->save();
    }

    private function toDomain(NotificationTemplateModel $m): NotificationTemplate
    {
        return NotificationTemplate::reconstitute(
            id: $m->id,
            key: $m->key,
            channel: NotificationChannel::from($m->channel),
            locale: $m->locale,
            subject: $m->subject,
            body: $m->body,
        );
    }
}
