<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Cms\FaqItem;
use EruoFood\Admin\Domain\Cms\FaqRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\FaqModel;
use Illuminate\Support\Str;

final class EloquentFaqRepository implements FaqRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?FaqItem
    {
        $m = FaqModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(?string $category = null): array
    {
        $builder = FaqModel::query();
        if ($category !== null) {
            $builder->where('category', $category);
        }

        return array_map(
            fn (FaqModel $m): FaqItem => $this->toDomain($m),
            $builder->orderBy('category')->orderBy('sort_order')->get()->all(),
        );
    }

    public function save(FaqItem $item): void
    {
        $model = FaqModel::query()->find($item->id()) ?? new FaqModel();
        $model->id = $item->id();
        $model->question = $item->question();
        $model->answer = $item->answer();
        $model->category = $item->category();
        $model->sort_order = $item->sortOrder();
        $model->published = $item->isPublished();
        $model->created_at = $item->createdAt();
        $model->updated_at = $item->updatedAt();
        $model->save();
    }

    public function delete(string $id): void
    {
        FaqModel::query()->whereKey($id)->delete();
    }

    private function toDomain(FaqModel $m): FaqItem
    {
        return FaqItem::reconstitute(
            id: $m->id,
            question: $m->question,
            answer: $m->answer,
            category: $m->category,
            sortOrder: (int) $m->sort_order,
            published: (bool) $m->published,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
