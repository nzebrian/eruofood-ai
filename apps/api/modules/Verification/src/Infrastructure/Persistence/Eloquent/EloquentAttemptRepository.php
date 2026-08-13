<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Verification\Domain\Attempt\AttemptRepository;
use EruoFood\Verification\Domain\Attempt\VerificationAttempt;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationAttemptModel;
use Illuminate\Support\Str;

final class EloquentAttemptRepository implements AttemptRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findByProviderReference(string $providerReference): ?VerificationAttempt
    {
        $model = VerificationAttemptModel::query()->where('provider_reference', $providerReference)->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function forCase(string $caseId): array
    {
        $models = VerificationAttemptModel::query()
            ->where('case_id', $caseId)
            ->orderByDesc('started_at')
            ->get();

        return array_values(array_map(fn (VerificationAttemptModel $m): VerificationAttempt => $this->toDomain($m), $models->all()));
    }

    public function save(VerificationAttempt $attempt): void
    {
        $model = VerificationAttemptModel::query()->find($attempt->id()) ?? new VerificationAttemptModel();
        $model->id = $attempt->id();
        $model->case_id = $attempt->caseId();
        $model->provider = $attempt->provider()->value;
        $model->provider_reference = $attempt->providerReference();
        $model->status = $attempt->status()->value;
        $model->raw_provider_status = $attempt->rawProviderStatus();
        $model->reason_code = $attempt->reasonCode()?->value;
        $model->started_at = $attempt->startedAt();
        $model->decided_at = $attempt->decidedAt();
        $model->save();
    }

    private function toDomain(VerificationAttemptModel $m): VerificationAttempt
    {
        return VerificationAttempt::reconstitute(
            id: $m->id,
            caseId: $m->case_id,
            provider: ProviderName::from($m->provider),
            providerReference: $m->provider_reference,
            status: VerificationStatus::from($m->status),
            rawProviderStatus: $m->raw_provider_status,
            reasonCode: $m->reason_code !== null ? RejectionReason::tryFrom($m->reason_code) : null,
            startedAt: DateTimeImmutable::createFromInterface($m->started_at),
            decidedAt: $m->decided_at !== null ? DateTimeImmutable::createFromInterface($m->decided_at) : null,
        );
    }
}
