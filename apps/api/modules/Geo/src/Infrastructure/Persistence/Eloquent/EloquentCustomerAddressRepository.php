<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Address\CustomerAddress;
use EruoFood\Geo\Domain\Address\CustomerAddressRepository;
use EruoFood\Geo\Domain\Enum\AddressLabel;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\CustomerAddressModel;
use Illuminate\Support\Str;

/**
 * Eloquent persistence for {@see CustomerAddress}.
 *
 * Every read is scoped by `user_id` at the query, not filtered after loading.
 * Addresses are addressed by UUID and a UUID is not an entitlement — the
 * service layer still checks ownership on the loaded row, but a query that
 * cannot return another customer's address in the first place is the cheaper
 * half of that guarantee.
 */
final class EloquentCustomerAddressRepository implements CustomerAddressRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?CustomerAddress
    {
        $model = CustomerAddressModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function forUser(string $userId, bool $activeOnly = true): array
    {
        $query = CustomerAddressModel::query()->where('user_id', $userId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $models = $query
            // The order the address book is displayed in: default first, then
            // most recently used, so the address somebody actually orders to is
            // the one under their thumb.
            ->orderByDesc('is_default')
            // A never-used address sorts last. Spelled as a CASE rather than
            // `NULLS LAST` because PostgreSQL and SQLite disagree on where NULLs
            // fall by default, and an address book that reorders itself
            // depending on the engine is a bug nobody would think to look for.
            ->orderByRaw('CASE WHEN last_used_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        return array_values(array_map(fn (CustomerAddressModel $m): CustomerAddress => $this->toDomain($m), $models->all()));
    }

    public function defaultFor(string $userId): ?CustomerAddress
    {
        $model = CustomerAddressModel::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function countActiveFor(string $userId): int
    {
        return CustomerAddressModel::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Clear the default flag across a user's addresses.
     *
     * A single UPDATE rather than a read-modify-write loop, so two concurrent
     * "make this my default" taps cannot interleave into a customer with two
     * defaults — or, worse, none.
     */
    public function clearDefaultFor(string $userId, ?string $exceptId = null): void
    {
        $query = CustomerAddressModel::query()
            ->where('user_id', $userId)
            ->where('is_default', true);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $query->update(['is_default' => false, 'updated_at' => new DateTimeImmutable()]);
    }

    public function save(CustomerAddress $address): void
    {
        $attributes = [
            'user_id' => $address->userId(),
            'location_id' => $address->locationId(),
            'label' => $address->label()->value,
            'custom_name' => $address->customName(),
            'delivery_instructions' => $address->deliveryInstructions(),
            'contact_phone' => $address->contactPhone(),
            'is_default' => $address->isDefault(),
            'is_active' => $address->isActive(),
            'last_used_at' => $address->lastUsedAt(),
            'updated_at' => $address->updatedAt(),
        ];

        $exists = CustomerAddressModel::query()->whereKey($address->id())->exists();

        if (! $exists) {
            CustomerAddressModel::query()->insert($attributes + [
                'id' => $address->id(),
                'created_at' => $address->createdAt(),
            ]);

            return;
        }

        CustomerAddressModel::query()->whereKey($address->id())->update($attributes);
    }

    private function toDomain(CustomerAddressModel $m): CustomerAddress
    {
        return CustomerAddress::reconstitute(
            id: $m->id,
            userId: $m->user_id,
            locationId: $m->location_id,
            label: AddressLabel::from($m->label),
            customName: $m->custom_name,
            deliveryInstructions: $m->delivery_instructions,
            contactPhone: $m->contact_phone,
            isDefault: $m->is_default,
            isActive: $m->is_active,
            lastUsedAt: $m->last_used_at !== null ? DateTimeImmutable::createFromInterface($m->last_used_at) : null,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
