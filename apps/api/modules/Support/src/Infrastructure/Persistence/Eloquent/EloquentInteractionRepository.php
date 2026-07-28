<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Support\Domain\Crm\Interaction;
use EruoFood\Support\Domain\Crm\InteractionRepository;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\Model\InteractionModel;
use Illuminate\Support\Str;

final class EloquentInteractionRepository implements InteractionRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function append(Interaction $interaction): void
    {
        $model = new InteractionModel();
        $model->id = $interaction->id;
        $model->user_id = $interaction->userId;
        $model->kind = $interaction->kind;
        $model->summary = $interaction->summary;
        $model->ref = $interaction->ref;
        $model->source = $interaction->source;
        $model->occurred_at = $interaction->occurredAt;
        $model->save();
    }

    public function forUser(string $userId, int $page, int $perPage): Paginated
    {
        $paginator = InteractionModel::query()->where('user_id', $userId)
            ->orderByDesc('occurred_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (InteractionModel $m): Interaction => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    private function toDomain(InteractionModel $m): Interaction
    {
        return new Interaction(
            $m->id,
            $m->user_id,
            $m->kind,
            $m->summary,
            $m->ref,
            $m->source,
            DateTimeImmutable::createFromInterface($m->occurred_at),
        );
    }
}
