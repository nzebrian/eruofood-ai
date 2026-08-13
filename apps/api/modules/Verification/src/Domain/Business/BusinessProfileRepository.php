<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Business;

/** Persistence port for {@see BusinessProfile} and its representatives. */
interface BusinessProfileRepository
{
    public function nextIdentity(): string;

    public function nextRepresentativeIdentity(): string;

    public function findById(string $id): ?BusinessProfile;

    public function findForBusiness(string $businessKind, string $businessId): ?BusinessProfile;

    /** @return list<BusinessRepresentative> */
    public function representativesFor(string $businessProfileId): array;

    public function findRepresentative(string $id): ?BusinessRepresentative;

    public function save(BusinessProfile $profile): void;

    public function saveRepresentative(BusinessRepresentative $representative): void;
}
