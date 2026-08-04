<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Method\SavedPaymentMethod;
use EruoFood\Payments\Domain\Method\SavedPaymentMethodRepository;
use EruoFood\Payments\Domain\ValueObject\CardFingerprint;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\SavedPaymentMethodModel;
use Illuminate\Support\Str;

final class EloquentSavedPaymentMethodRepository implements SavedPaymentMethodRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?SavedPaymentMethod
    {
        $m = SavedPaymentMethodModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forUser(string $userId): array
    {
        return array_values(array_map(
            fn (SavedPaymentMethodModel $m): SavedPaymentMethod => $this->toDomain($m),
            SavedPaymentMethodModel::query()->where('user_id', $userId)->orderByDesc('is_default')->orderByDesc('created_at')->get()->all(),
        ));
    }

    public function clearDefaultFor(string $userId): void
    {
        SavedPaymentMethodModel::query()->where('user_id', $userId)->update(['is_default' => false]);
    }

    public function save(SavedPaymentMethod $method): void
    {
        $model = SavedPaymentMethodModel::query()->find($method->id()) ?? new SavedPaymentMethodModel();
        $model->id = $method->id();
        $model->user_id = $method->userId();
        $model->provider = $method->provider()->value;
        $model->card = $method->card()->toArray();
        $model->is_default = $method->isDefault();
        $model->created_at = $method->createdAt();
        $model->save();
    }

    public function delete(string $id): void
    {
        SavedPaymentMethodModel::query()->where('id', $id)->delete();
    }

    private function toDomain(SavedPaymentMethodModel $m): SavedPaymentMethod
    {
        return SavedPaymentMethod::reconstitute(
            id: $m->id,
            userId: $m->user_id,
            provider: PaymentProvider::from($m->provider),
            card: CardFingerprint::fromArray($m->card ?? []),
            default: $m->is_default,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
