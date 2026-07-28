<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\Rating\RatingSummary;
use EruoFood\Reviews\Domain\Rating\RatingSummaryRepository;
use EruoFood\Reviews\Domain\ValueObject\Subject;
use EruoFood\Reviews\Infrastructure\Persistence\Eloquent\Model\RatingSummaryModel;

final class EloquentRatingSummaryRepository implements RatingSummaryRepository
{
    public function findBySubject(Subject $subject): ?RatingSummary
    {
        $m = RatingSummaryModel::query()->find($this->key($subject));

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function save(RatingSummary $summary): void
    {
        $model = RatingSummaryModel::query()->find($this->key($summary->subject)) ?? new RatingSummaryModel();
        $model->id = $this->key($summary->subject);
        $model->subject_type = $summary->subject->type->value;
        $model->subject_id = $summary->subject->id;
        $model->count = $summary->count;
        $model->average = $summary->average;
        $model->distribution = $summary->distribution;
        $model->verified_count = $summary->verifiedCount;
        $model->updated_at = $summary->updatedAt;
        $model->save();
    }

    public function topRated(SubjectType $type, int $minCount, int $limit): array
    {
        return array_map(
            fn (RatingSummaryModel $m): RatingSummary => $this->toDomain($m),
            RatingSummaryModel::query()
                ->where('subject_type', $type->value)
                ->where('count', '>=', $minCount)
                ->orderByDesc('average')
                ->orderByDesc('count')
                ->limit($limit)
                ->get()
                ->all(),
        );
    }

    private function key(Subject $subject): string
    {
        return $subject->key();
    }

    private function toDomain(RatingSummaryModel $m): RatingSummary
    {
        /** @var array<int|string, mixed> $raw */
        $raw = $m->distribution ?? [];
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($raw as $star => $count) {
            $distribution[(int) $star] = (int) $count;
        }

        return new RatingSummary(
            new Subject(SubjectType::from($m->subject_type), $m->subject_id),
            (int) $m->count,
            (float) $m->average,
            $distribution,
            (int) $m->verified_count,
            DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
