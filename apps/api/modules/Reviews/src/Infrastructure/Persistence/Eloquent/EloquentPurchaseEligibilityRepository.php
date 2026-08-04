<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Reviews\Domain\Eligibility\PurchaseEligibilityRepository;
use EruoFood\Reviews\Domain\ValueObject\Subject;
use EruoFood\Reviews\Infrastructure\Persistence\Eloquent\Model\PurchaseEligibilityModel;

/**
 * The event-fed verified-purchase ledger. Each (user, subject) pair is recorded
 * idempotently from a published order event; the key is deterministic so a
 * replayed event never creates a duplicate row.
 */
final class EloquentPurchaseEligibilityRepository implements PurchaseEligibilityRepository
{
    public function record(string $userId, Subject $subject): void
    {
        $id = $this->key($userId, $subject);
        if (PurchaseEligibilityModel::query()->whereKey($id)->exists()) {
            return;
        }
        $model = new PurchaseEligibilityModel();
        $model->id = $id;
        $model->user_id = $userId;
        $model->subject_type = $subject->type->value;
        $model->subject_id = $subject->id;
        $model->created_at = new DateTimeImmutable();
        $model->save();
    }

    public function isEligible(string $userId, Subject $subject): bool
    {
        return PurchaseEligibilityModel::query()->whereKey($this->key($userId, $subject))->exists();
    }

    private function key(string $userId, Subject $subject): string
    {
        return hash('sha256', $userId.'|'.$subject->key());
    }
}
