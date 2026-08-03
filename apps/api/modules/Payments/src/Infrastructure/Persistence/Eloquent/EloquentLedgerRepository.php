<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Enum\TransactionDirection;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Ledger\LedgerEntry;
use EruoFood\Payments\Domain\Ledger\LedgerRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\LedgerEntryModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentLedgerRepository implements LedgerRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function post(array $entries): void
    {
        DB::transaction(function () use ($entries): void {
            foreach ($entries as $entry) {
                $model = new LedgerEntryModel();
                $model->id = $entry->id;
                $model->correlation_id = $entry->correlationId;
                $model->account = $entry->account->value;
                $model->direction = $entry->direction->value;
                $model->amount_minor = $entry->amount->minorUnits;
                $model->currency = $entry->amount->currency;
                $model->type = $entry->type->value;
                $model->reference = $entry->reference;
                $model->posted_at = $entry->postedAt;
                $model->save();
            }
        });
    }

    public function balanceOf(LedgerAccount $account): int
    {
        $credit = (int) LedgerEntryModel::query()->where('account', $account->value)
            ->where('direction', TransactionDirection::Credit->value)->sum('amount_minor');
        $debit = (int) LedgerEntryModel::query()->where('account', $account->value)
            ->where('direction', TransactionDirection::Debit->value)->sum('amount_minor');

        return $credit - $debit;
    }

    public function forAccount(LedgerAccount $account, int $page, int $perPage): Paginated
    {
        $paginator = LedgerEntryModel::query()->where('account', $account->value)
            ->orderByDesc('posted_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (LedgerEntryModel $m): LedgerEntry => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function forCorrelation(string $correlationId): array
    {
        return array_values(array_map(
            fn (LedgerEntryModel $m): LedgerEntry => $this->toDomain($m),
            LedgerEntryModel::query()->where('correlation_id', $correlationId)->get()->all(),
        ));
    }

    private function toDomain(LedgerEntryModel $m): LedgerEntry
    {
        return new LedgerEntry(
            id: $m->id,
            correlationId: $m->correlation_id,
            account: LedgerAccount::from($m->account),
            direction: TransactionDirection::from($m->direction),
            amount: new Money((int) $m->amount_minor, $m->currency ?: $this->currency),
            type: TransactionType::from($m->type),
            reference: $m->reference,
            postedAt: DateTimeImmutable::createFromInterface($m->posted_at),
        );
    }
}
