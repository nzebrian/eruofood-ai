<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\Application\Application;
use EruoFood\PublicApi\Domain\Application\ApplicationRepository;
use EruoFood\PublicApi\Domain\Enum\ApplicationStatus;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model\ApplicationModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentApplicationRepository implements ApplicationRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Application
    {
        $m = ApplicationModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forDeveloper(string $developerId, int $page, int $perPage): Paginated
    {
        $paginator = ApplicationModel::query()->where('developer_id', $developerId)
            ->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (ApplicationModel $m): Application => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Application $a): void
    {
        $m = ApplicationModel::query()->find($a->id()) ?? new ApplicationModel();
        $m->id = $a->id();
        $m->developer_id = $a->developerId();
        $m->name = $a->name();
        $m->description = $a->description();
        $m->scopes = $a->scopes()->toArray();
        $m->status = $a->status()->value;
        $m->created_at = $a->createdAt();
        $m->updated_at = $a->updatedAt();
        $m->save();
    }

    private function toDomain(ApplicationModel $m): Application
    {
        return Application::reconstitute(
            $m->id,
            $m->developer_id,
            $m->name,
            (string) ($m->description ?? ''),
            ScopeSet::fromArray($m->scopes ?? []),
            ApplicationStatus::from($m->status),
            DateTimeImmutable::createFromInterface($m->created_at),
            DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
