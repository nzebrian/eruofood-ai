<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Account\LedgerEntry;
use EruoFood\Loyalty\Domain\Account\LedgerQuery;
use EruoFood\Loyalty\Domain\Account\LoyaltyAccount;
use EruoFood\Loyalty\Domain\Account\LoyaltyAccountRepository;
use EruoFood\Loyalty\Domain\Enum\LedgerEntryType;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\AccountModel;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\LedgerEntryModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentLoyaltyAccountRepository implements LoyaltyAccountRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function nextEntryIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?LoyaltyAccount
    {
        $m = AccountModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByUser(string $userId): ?LoyaltyAccount
    {
        $m = AccountModel::query()->where('user_id', $userId)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function ledger(LedgerQuery $query): Paginated
    {
        $builder = LedgerEntryModel::query()->where('account_id', $query->accountId);
        if ($query->type !== null) {
            $builder->where('type', $query->type->value);
        }
        $paginator = $builder->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(perPage: $query->perPage, page: $query->page);

        return new Paginated(
            array_values(array_map(fn (LedgerEntryModel $m): LedgerEntry => $this->entryToDomain($m), $paginator->items())),
            $paginator->total(),
            $query->page,
            $query->perPage,
        );
    }

    public function expirableEntries(DateTimeImmutable $now, int $limit): array
    {
        return array_values(array_map(
            fn (LedgerEntryModel $m): LedgerEntry => $this->entryToDomain($m),
            LedgerEntryModel::query()
                ->where('type', LedgerEntryType::Earn->value)
                ->where('points', '>', 0)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now->format('Y-m-d H:i:s'))
                ->orderBy('expires_at')
                ->limit($limit)
                ->get()
                ->all(),
        ));
    }

    public function expiredAgainst(string $earnEntryId): int
    {
        $sum = (int) LedgerEntryModel::query()
            ->where('type', LedgerEntryType::Expire->value)
            ->where('reference', $earnEntryId)
            ->sum('points');

        return abs($sum);
    }

    public function save(LoyaltyAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            $model = AccountModel::query()->find($account->id()) ?? new AccountModel();
            $model->id = $account->id();
            $model->user_id = $account->userId();
            $model->balance = $account->balance();
            $model->lifetime_points = $account->lifetimePoints();
            $model->tier_key = $account->tierKey();
            $model->created_at = $account->createdAt();
            $model->updated_at = $account->updatedAt();
            $model->save();

            foreach ($account->releaseNewEntries() as $entry) {
                $row = new LedgerEntryModel();
                $row->id = $entry->id;
                $row->account_id = $entry->accountId;
                $row->type = $entry->type->value;
                $row->points = $entry->points;
                $row->reason = $entry->reason;
                $row->reference = $entry->reference;
                $row->balance_after = $entry->balanceAfter;
                $row->created_at = $entry->createdAt;
                $row->expires_at = $entry->expiresAt;
                $row->save();
            }
        });
    }

    private function toDomain(AccountModel $m): LoyaltyAccount
    {
        return LoyaltyAccount::reconstitute(
            $m->id,
            $m->user_id,
            (int) $m->balance,
            (int) $m->lifetime_points,
            $m->tier_key,
            DateTimeImmutable::createFromInterface($m->created_at),
            DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }

    private function entryToDomain(LedgerEntryModel $m): LedgerEntry
    {
        return new LedgerEntry(
            $m->id,
            $m->account_id,
            LedgerEntryType::from($m->type),
            (int) $m->points,
            $m->reason,
            $m->reference,
            (int) $m->balance_after,
            DateTimeImmutable::createFromInterface($m->created_at),
            $m->expires_at !== null ? DateTimeImmutable::createFromInterface($m->expires_at) : null,
        );
    }
}
