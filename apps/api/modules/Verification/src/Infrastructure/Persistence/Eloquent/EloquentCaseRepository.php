<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationLevel;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\VerificationCase\CaseRepository;
use EruoFood\Verification\Domain\VerificationCase\StatusChange;
use EruoFood\Verification\Domain\VerificationCase\VerificationCase;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationCaseModel;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationEventModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Eloquent persistence for verification cases.
 *
 * Follows the M23 wallet repository shape for the same reasons: a locking read
 * for anything that will be written back, and a version-checked update so a
 * missed lock becomes a loud {@see ConcurrencyConflict} rather than a silent
 * overwrite of somebody's verification decision.
 */
final class EloquentCaseRepository implements CaseRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?VerificationCase
    {
        $model = VerificationCaseModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findByIdForUpdate(string $id): ?VerificationCase
    {
        $model = VerificationCaseModel::query()->whereKey($id)->lockForUpdate()->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findOpenFor(SubjectType $type, string $subjectId, CaseType $caseType): ?VerificationCase
    {
        $model = VerificationCaseModel::query()
            ->where('open_key', sprintf('%s:%s:%s', $type->value, $subjectId, $caseType->value))
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findLatestFor(SubjectType $type, string $subjectId, CaseType $caseType): ?VerificationCase
    {
        $model = VerificationCaseModel::query()
            ->where('subject_type', $type->value)
            ->where('subject_id', $subjectId)
            ->where('case_type', $caseType->value)
            // A verified case is the answer even if a later attempt is in
            // flight: starting a reverification must not make somebody
            // instantly ineligible while it is pending.
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [VerificationStatus::Verified->value])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findByProviderReferenceForUpdate(string $providerReference): ?VerificationCase
    {
        $model = VerificationCaseModel::query()
            ->where('provider_reference', $providerReference)
            ->lockForUpdate()
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function queue(array $statuses, ?SubjectType $subjectType, int $page, int $perPage): Paginated
    {
        $query = VerificationCaseModel::query()
            ->whereIn('status', array_map(static fn (VerificationStatus $s): string => $s->value, $statuses));

        if ($subjectType !== null) {
            $query->where('subject_type', $subjectType->value);
        }

        // Oldest first: a review queue that surfaces the longest-waiting case is
        // the one that keeps merchants and riders from being forgotten.
        $paginator = $query->orderBy('updated_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (VerificationCaseModel $m): VerificationCase => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function stalledSince(DateTimeImmutable $before, int $limit): array
    {
        $models = VerificationCaseModel::query()
            ->whereIn('status', [VerificationStatus::Pending->value, VerificationStatus::Processing->value])
            ->whereNotNull('provider_reference')
            ->where('updated_at', '<', $before)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        return array_values(array_map(fn (VerificationCaseModel $m): VerificationCase => $this->toDomain($m), $models->all()));
    }

    public function expiredBy(DateTimeImmutable $now, int $limit): array
    {
        $models = VerificationCaseModel::query()
            ->where('status', VerificationStatus::Verified->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        return array_values(array_map(fn (VerificationCaseModel $m): VerificationCase => $this->toDomain($m), $models->all()));
    }

    public function history(string $caseId): array
    {
        $rows = VerificationEventModel::query()
            ->where('case_id', $caseId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return array_values(array_map(static fn (VerificationEventModel $m): StatusChange => new StatusChange(
            caseId: $m->case_id,
            from: VerificationStatus::from($m->from_status),
            to: VerificationStatus::from($m->to_status),
            actorType: \EruoFood\Verification\Domain\Enum\ActorType::from($m->actor_type),
            actorId: $m->actor_id,
            reasonCode: $m->reason_code,
            note: (string) $m->note,
            occurredAt: DateTimeImmutable::createFromInterface($m->occurred_at),
        ), $rows->all()));
    }

    public function save(VerificationCase $case): void
    {
        DB::transaction(function () use ($case): void {
            $this->persistCase($case);

            foreach ($case->releaseStatusChanges() as $change) {
                $row = new VerificationEventModel();
                $row->id = (string) Str::orderedUuid();
                $row->case_id = $change->caseId;
                $row->from_status = $change->from->value;
                $row->to_status = $change->to->value;
                $row->actor_type = $change->actorType->value;
                $row->actor_id = $change->actorId;
                $row->reason_code = $change->reasonCode;
                $row->note = $change->note;
                $row->occurred_at = $change->occurredAt;
                $row->save();
            }
        });
    }

    /**
     * Insert a new case, or update an existing one only if nobody else has
     * written it since we read it.
     *
     * The UPDATE carries the loaded version in its WHERE clause, so a concurrent
     * writer that already committed makes this statement match zero rows — which
     * is how a lost verification decision is detected instead of silently
     * winning.
     */
    private function persistCase(VerificationCase $case): void
    {
        $attributes = [
            'subject_type' => $case->subjectType()->value,
            'subject_id' => $case->subjectId(),
            'case_type' => $case->caseType()->value,
            'country_code' => $case->countryCode(),
            'requested_level' => $case->requestedLevel()->value,
            'status' => $case->status()->value,
            'provider' => $case->provider()?->value,
            'provider_reference' => $case->providerReference(),
            'decision_reason_code' => $case->rejectionReason()?->value,
            'review_note' => $case->reviewNote(),
            'verified_at' => $case->verifiedAt(),
            'expires_at' => $case->expiresAt(),
            'open_key' => $case->openKey(),
            'contact_user_id' => $case->contactUserId(),
            'created_at' => $case->createdAt(),
            'updated_at' => $case->updatedAt(),
        ];

        $exists = VerificationCaseModel::query()->whereKey($case->id())->exists();

        if (! $exists) {
            VerificationCaseModel::query()->insert($attributes + ['id' => $case->id(), 'version' => 1]);

            return;
        }

        $updated = VerificationCaseModel::query()
            ->whereKey($case->id())
            ->where('version', $case->version())
            ->update($attributes + ['version' => $case->version() + 1]);

        if ($updated === 0) {
            throw ConcurrencyConflict::on('verification case', $case->id());
        }
    }

    private function toDomain(VerificationCaseModel $m): VerificationCase
    {
        return VerificationCase::reconstitute(
            id: $m->id,
            subjectType: SubjectType::from($m->subject_type),
            subjectId: $m->subject_id,
            caseType: CaseType::from($m->case_type),
            countryCode: $m->country_code,
            requestedLevel: VerificationLevel::from($m->requested_level),
            status: VerificationStatus::from($m->status),
            provider: $m->provider !== null ? ProviderName::from($m->provider) : null,
            providerReference: $m->provider_reference,
            rejectionReason: $m->decision_reason_code !== null ? RejectionReason::tryFrom($m->decision_reason_code) : null,
            reviewNote: $m->review_note,
            verifiedAt: $m->verified_at !== null ? DateTimeImmutable::createFromInterface($m->verified_at) : null,
            expiresAt: $m->expires_at !== null ? DateTimeImmutable::createFromInterface($m->expires_at) : null,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
            version: (int) $m->version,
            contactUserId: $m->contact_user_id,
        );
    }
}
