<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Persistence\Eloquent;

use EruoFood\Ai\Domain\Usage\AiUsageLog;
use EruoFood\Ai\Domain\Usage\AiUsageLogRepository;
use EruoFood\Ai\Domain\Usage\UsageSummary;
use EruoFood\Ai\Infrastructure\Persistence\Eloquent\Model\AiUsageLogModel;
use Illuminate\Support\Str;

/** Eloquent-backed {@see AiUsageLogRepository} — the AI usage & cost ledger. */
final class EloquentAiUsageLogRepository implements AiUsageLogRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function record(AiUsageLog $log): void
    {
        $model = new AiUsageLogModel();
        $model->id = $log->id();
        $model->user_id = $log->userId();
        $model->feature = $log->feature()->value;
        $model->provider = $log->provider()->value;
        $model->model = $log->model();
        $model->input_tokens = $log->tokens()->inputTokens;
        $model->output_tokens = $log->tokens()->outputTokens;
        $model->total_tokens = $log->tokens()->total();
        $model->cost_usd = $log->costUsd();
        $model->cached = $log->wasCached();
        $model->latency_ms = $log->latencyMs();
        $model->success = $log->wasSuccessful();
        $model->error_code = $log->errorCode();
        $model->created_at = $log->createdAt();
        $model->save();
    }

    public function summaryForUser(string $userId, int $sinceDays): UsageSummary
    {
        $row = AiUsageLogModel::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($sinceDays))
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(cost_usd), 0) as cost_usd')
            ->selectRaw('COALESCE(SUM(CASE WHEN cached THEN 1 ELSE 0 END), 0) as cached_requests')
            ->first();

        return new UsageSummary(
            requests: (int) ($row->requests ?? 0),
            inputTokens: (int) ($row->input_tokens ?? 0),
            outputTokens: (int) ($row->output_tokens ?? 0),
            costUsd: (float) ($row->cost_usd ?? 0),
            cachedRequests: (int) ($row->cached_requests ?? 0),
        );
    }

    public function countForUserSince(string $userId, int $sinceSeconds): int
    {
        return AiUsageLogModel::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subSeconds($sinceSeconds))
            ->count();
    }
}
