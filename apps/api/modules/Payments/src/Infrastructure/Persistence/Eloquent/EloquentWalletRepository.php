<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Wallet\Wallet;
use EruoFood\Payments\Domain\Wallet\WalletRepository;
use EruoFood\Payments\Domain\Wallet\WalletTransaction;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\WalletModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\WalletTransactionModel;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentWalletRepository implements WalletRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function nextTransactionId(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Wallet
    {
        $m = WalletModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findForOwner(WalletOwnerType $ownerType, string $ownerId): ?Wallet
    {
        $m = WalletModel::query()->where('owner_type', $ownerType->value)->where('owner_id', $ownerId)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByIdForUpdate(string $id): ?Wallet
    {
        $m = WalletModel::query()->whereKey($id)->lockForUpdate()->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findForOwnerForUpdate(WalletOwnerType $ownerType, string $ownerId): ?Wallet
    {
        $m = WalletModel::query()
            ->where('owner_type', $ownerType->value)
            ->where('owner_id', $ownerId)
            ->lockForUpdate()
            ->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function save(Wallet $wallet): void
    {
        DB::transaction(function () use ($wallet): void {
            $this->persistWallet($wallet);

            foreach ($wallet->releaseNewTransactions() as $txn) {
                $row = new WalletTransactionModel();
                $row->id = $txn->id;
                $row->wallet_id = $txn->walletId;
                $row->type = $txn->type->value;
                $row->direction = $txn->direction->value;
                $row->amount_minor = $txn->amount->minorUnits;
                $row->balance_after_minor = $txn->balanceAfter->minorUnits;
                $row->currency = $txn->amount->currency;
                $row->reference = $txn->reference;
                $row->description = $txn->description;
                $row->created_at = $txn->createdAt;
                $row->save();
            }
        });
    }

    /**
     * Insert a new wallet, or update an existing one only if nobody else has
     * written it since we read it.
     *
     * The UPDATE carries the loaded version in its WHERE clause. A concurrent
     * writer that already committed has bumped the version, so this statement
     * matches zero rows — which is how a lost update is detected instead of
     * silently winning.
     */
    private function persistWallet(Wallet $wallet): void
    {
        $attributes = [
            'owner_type' => $wallet->ownerType()->value,
            'owner_id' => $wallet->ownerId(),
            'balance_minor' => $wallet->balance()->minorUnits,
            'currency' => $wallet->currency(),
            'created_at' => $wallet->createdAt(),
        ];

        $exists = WalletModel::query()->whereKey($wallet->id())->exists();

        if (! $exists) {
            // low_balance_threshold is omitted so the column default applies,
            // matching the behaviour this repository has always had.
            WalletModel::query()->insert($attributes + ['id' => $wallet->id(), 'version' => 1]);

            return;
        }

        $updated = WalletModel::query()
            ->whereKey($wallet->id())
            ->where('version', $wallet->version())
            ->update($attributes + ['version' => $wallet->version() + 1]);

        if ($updated === 0) {
            throw ConcurrencyConflict::on('wallet', $wallet->id());
        }
    }

    public function statement(string $walletId, int $page, int $perPage): Paginated
    {
        $paginator = WalletTransactionModel::query()->where('wallet_id', $walletId)
            ->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(function (WalletTransactionModel $m): WalletTransaction {
                return WalletTransaction::fromArray([
                    'id' => $m->id,
                    'wallet_id' => $m->wallet_id,
                    'type' => $m->type,
                    'direction' => $m->direction,
                    'amount_minor' => $m->amount_minor,
                    'balance_after_minor' => $m->balance_after_minor,
                    'reference' => $m->reference,
                    'description' => $m->description,
                    'created_at' => DateTimeImmutable::createFromInterface($m->created_at)->format(DATE_ATOM),
                ], $m->currency ?: $this->currency);
            }, $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    private function toDomain(WalletModel $m): Wallet
    {
        return Wallet::reconstitute(
            id: $m->id,
            ownerType: WalletOwnerType::from($m->owner_type),
            ownerId: $m->owner_id,
            balance: new Money((int) $m->balance_minor, $m->currency ?: $this->currency),
            currency: $m->currency ?: $this->currency,
            lowBalanceThreshold: (int) $m->low_balance_threshold,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            version: (int) $m->version,
        );
    }
}
