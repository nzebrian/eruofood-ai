<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Support\Domain\Csat\CsatRepository;
use EruoFood\Support\Domain\Csat\CsatResponse;
use EruoFood\Support\Domain\Csat\CsatSummary;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\Model\CsatModel;
use Illuminate\Support\Str;

final class EloquentCsatRepository implements CsatRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findByTicket(string $ticketId): ?CsatResponse
    {
        $m = CsatModel::query()->where('ticket_id', $ticketId)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function save(CsatResponse $response): void
    {
        $model = CsatModel::query()->find($response->id) ?? new CsatModel();
        $model->id = $response->id;
        $model->ticket_id = $response->ticketId;
        $model->user_id = $response->userId;
        $model->score = $response->score;
        $model->comment = $response->comment;
        $model->agent_id = $response->agentId;
        $model->created_at = $response->createdAt;
        $model->save();
    }

    public function summary(int $days): CsatSummary
    {
        $since = (new DateTimeImmutable('-'.max(1, $days).' days'))->format('Y-m-d H:i:s');
        /** @var list<CsatModel> $rows */
        $rows = CsatModel::query()->where('created_at', '>=', $since)->get()->all();

        $count = count($rows);
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $sum = 0;
        $satisfied = 0;
        foreach ($rows as $row) {
            $score = (int) $row->score;
            $sum += $score;
            if (isset($distribution[$score])) {
                $distribution[$score]++;
            }
            if ($score >= 4) {
                $satisfied++;
            }
        }

        return new CsatSummary(
            responses: $count,
            average: $count > 0 ? $sum / $count : 0.0,
            distribution: $distribution,
            satisfactionRate: $count > 0 ? $satisfied / $count : 0.0,
        );
    }

    private function toDomain(CsatModel $m): CsatResponse
    {
        return new CsatResponse(
            $m->id,
            $m->ticket_id,
            $m->user_id,
            (int) $m->score,
            $m->comment,
            $m->agent_id,
            DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
