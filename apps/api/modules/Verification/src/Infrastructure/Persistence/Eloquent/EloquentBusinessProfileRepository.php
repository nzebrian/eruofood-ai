<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Verification\Application\Port\FieldEncryptor;
use EruoFood\Verification\Domain\Business\BusinessProfile;
use EruoFood\Verification\Domain\Business\BusinessProfileRepository;
use EruoFood\Verification\Domain\Business\BusinessRepresentative;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\BusinessProfileModel;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\BusinessRepresentativeModel;
use Illuminate\Support\Str;
use Throwable;

/**
 * Eloquent persistence for business profiles.
 *
 * The registration number is encrypted on the way in and decrypted on the way
 * out, so the domain works with it in the clear while storage never holds it
 * that way. Decryption failures degrade to an empty string rather than throwing:
 * a rotated key should make a number unreadable, not make the whole merchant
 * record unloadable.
 */
final readonly class EloquentBusinessProfileRepository implements BusinessProfileRepository
{
    public function __construct(private FieldEncryptor $encryptor)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function nextRepresentativeIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?BusinessProfile
    {
        $model = BusinessProfileModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findForBusiness(string $businessKind, string $businessId): ?BusinessProfile
    {
        $model = BusinessProfileModel::query()
            ->where('business_kind', $businessKind)
            ->where('business_id', $businessId)
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function representativesFor(string $businessProfileId): array
    {
        $models = BusinessRepresentativeModel::query()
            ->where('business_profile_id', $businessProfileId)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->get();

        return array_values(array_map(fn (BusinessRepresentativeModel $m): BusinessRepresentative => $this->representativeToDomain($m), $models->all()));
    }

    public function findRepresentative(string $id): ?BusinessRepresentative
    {
        $model = BusinessRepresentativeModel::query()->find($id);

        return $model !== null ? $this->representativeToDomain($model) : null;
    }

    public function save(BusinessProfile $profile): void
    {
        $model = BusinessProfileModel::query()->find($profile->id()) ?? new BusinessProfileModel();
        $model->id = $profile->id();
        $model->business_kind = $profile->businessKind();
        $model->business_id = $profile->businessId();
        $model->country_code = $profile->countryCode();
        $model->registered_name = $profile->registeredName();
        $model->trading_name = $profile->tradingName();
        $model->business_type = $profile->businessType();
        $model->registration_number = $this->encryptor->encrypt($profile->registrationNumber());
        $model->registration_authority = $profile->registrationAuthority();
        $model->address = $profile->address();
        $model->latitude = $profile->latitude();
        $model->longitude = $profile->longitude();
        $model->identity_case_id = $profile->identityCaseId();
        $model->payout_account_case_id = $profile->payoutAccountCaseId();
        $model->created_at = $profile->createdAt();
        $model->updated_at = $profile->updatedAt();
        $model->save();
    }

    public function saveRepresentative(BusinessRepresentative $representative): void
    {
        $model = BusinessRepresentativeModel::query()->find($representative->id()) ?? new BusinessRepresentativeModel();
        $model->id = $representative->id();
        $model->business_profile_id = $representative->businessProfileId();
        $model->user_id = $representative->userId();
        $model->full_name = $representative->fullName();
        $model->role = $representative->role();
        $model->is_primary = $representative->isPrimary();
        $model->identity_case_id = $representative->identityCaseId();
        $model->ownership_percentage = $representative->ownershipPercentage();
        $model->created_at = $representative->createdAt();
        $model->updated_at = $representative->updatedAt();
        $model->save();
    }

    private function toDomain(BusinessProfileModel $m): BusinessProfile
    {
        return BusinessProfile::reconstitute(
            id: $m->id,
            businessKind: $m->business_kind,
            businessId: $m->business_id,
            countryCode: $m->country_code,
            registeredName: $m->registered_name,
            tradingName: $m->trading_name,
            businessType: $m->business_type,
            registrationNumber: $this->decrypt($m->registration_number),
            registrationAuthority: $m->registration_authority,
            address: $m->address ?? [],
            latitude: $m->latitude,
            longitude: $m->longitude,
            identityCaseId: $m->identity_case_id,
            payoutAccountCaseId: $m->payout_account_case_id,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }

    private function representativeToDomain(BusinessRepresentativeModel $m): BusinessRepresentative
    {
        return BusinessRepresentative::reconstitute(
            id: $m->id,
            businessProfileId: $m->business_profile_id,
            userId: $m->user_id,
            fullName: $m->full_name,
            role: $m->role,
            isPrimary: (bool) $m->is_primary,
            identityCaseId: $m->identity_case_id,
            ownershipPercentage: $m->ownership_percentage,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }

    private function decrypt(string $ciphertext): string
    {
        try {
            return $this->encryptor->decrypt($ciphertext);
        } catch (Throwable) {
            // Unreadable (rotated key, corrupted row) — surface as empty rather
            // than making the merchant record impossible to load at all.
            return '';
        }
    }
}
