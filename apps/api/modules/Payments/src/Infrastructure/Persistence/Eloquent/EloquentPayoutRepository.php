<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\PayoutStatus;
use EruoFood\Payments\Domain\Settlement\Payout;
use EruoFood\Payments\Domain\Settlement\PayoutRepository;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PayoutModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Str;

final class EloquentPayoutRepository implements PayoutRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Payout
    {
        $m = PayoutModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(int $page, int $perPage): Paginated
    {
        $paginator = PayoutModel::query()->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (PayoutModel $m): Payout => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Payout $payout): void
    {
        $model = PayoutModel::query()->find($payout->id()) ?? new PayoutModel();
        $model->id = $payout->id();
        $model->payee_type = $payout->payeeType();
        $model->payee_id = $payout->payeeId();
        $model->amount_minor = $payout->amount()->minorUnits;
        $model->currency = $payout->amount()->currency;
        $model->destination = $payout->destination()->toArray();
        $model->status = $payout->status()->value;
        $model->provider_reference = $payout->providerReference();
        $model->created_at = $payout->createdAt();
        $model->paid_at = $payout->paidAt();
        $model->save();
    }

    private function toDomain(PayoutModel $m): Payout
    {
        return Payout::reconstitute(
            id: $m->id,
            payeeType: $m->payee_type,
            payeeId: $m->payee_id,
            amount: new Money((int) $m->amount_minor, $m->currency ?: $this->currency),
            destination: BankAccount::fromArray($m->destination ?? []),
            status: PayoutStatus::from($m->status),
            providerReference: $m->provider_reference,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            paidAt: $m->paid_at !== null ? DateTimeImmutable::createFromInterface($m->paid_at) : null,
        );
    }
}
