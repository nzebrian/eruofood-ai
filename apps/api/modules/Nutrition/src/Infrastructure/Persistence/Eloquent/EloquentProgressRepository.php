<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Nutrition\Domain\Progress\ProgressEntry;
use EruoFood\Nutrition\Domain\Progress\ProgressRepository;
use EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\Model\ProgressEntryModel;
use Illuminate\Support\Str;

final class EloquentProgressRepository implements ProgressRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function forUser(string $userId, int $limit = 90): array
    {
        return array_values(array_map(
            fn (ProgressEntryModel $m): ProgressEntry => $this->toDomain($m),
            ProgressEntryModel::query()
                ->where('user_id', $userId)
                ->orderByDesc('entry_date')
                ->limit($limit)
                ->get()
                ->all(),
        ));
    }

    public function latest(string $userId): ?ProgressEntry
    {
        $model = ProgressEntryModel::query()
            ->where('user_id', $userId)
            ->orderByDesc('entry_date')
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function save(ProgressEntry $entry): void
    {
        $model = ProgressEntryModel::query()->find($entry->id()) ?? new ProgressEntryModel();
        $model->id = $entry->id();
        $model->user_id = $entry->userId();
        $model->entry_date = $entry->date();
        $model->weight_kg = $entry->weightKg();
        $model->note = $entry->note();
        $model->created_at = $entry->recordedAt();
        $model->save();
    }

    private function toDomain(ProgressEntryModel $m): ProgressEntry
    {
        return ProgressEntry::create(
            id: $m->id,
            userId: $m->user_id,
            date: $m->entry_date,
            weightKg: $m->weight_kg,
            note: $m->note,
            recordedAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
