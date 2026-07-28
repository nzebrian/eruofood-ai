<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent;

use EruoFood\Support\Domain\Enum\TicketPriority;
use EruoFood\Support\Domain\Sla\SlaPolicy;
use EruoFood\Support\Domain\Sla\SlaPolicyRepository;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\Model\SlaPolicyModel;

final class EloquentSlaPolicyRepository implements SlaPolicyRepository
{
    public function findById(string $id): ?SlaPolicy
    {
        $m = SlaPolicyModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByPriority(TicketPriority $priority): ?SlaPolicy
    {
        $m = SlaPolicyModel::query()->where('priority', $priority->value)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(): array
    {
        return array_map(
            fn (SlaPolicyModel $m): SlaPolicy => $this->toDomain($m),
            SlaPolicyModel::query()->orderBy('resolution_minutes')->get()->all(),
        );
    }

    public function save(SlaPolicy $policy): void
    {
        $model = SlaPolicyModel::query()->find($policy->id()) ?? new SlaPolicyModel();
        $model->id = $policy->id();
        $model->name = $policy->name();
        $model->priority = $policy->priority()->value;
        $model->first_response_minutes = $policy->firstResponseMinutes();
        $model->resolution_minutes = $policy->resolutionMinutes();
        $model->save();
    }

    private function toDomain(SlaPolicyModel $m): SlaPolicy
    {
        return SlaPolicy::reconstitute(
            $m->id,
            $m->name,
            TicketPriority::from($m->priority),
            (int) $m->first_response_minutes,
            (int) $m->resolution_minutes,
        );
    }
}
