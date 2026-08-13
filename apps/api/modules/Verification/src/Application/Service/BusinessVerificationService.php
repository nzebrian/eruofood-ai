<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Service;

use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Verification\Application\DTO\RegistryLookup;
use EruoFood\Verification\Application\Port\BusinessRegistryRegistry;
use EruoFood\Verification\Domain\Business\BusinessProfile;
use EruoFood\Verification\Domain\Business\BusinessProfileRepository;
use EruoFood\Verification\Domain\Business\BusinessRepresentative;
use EruoFood\Verification\Domain\Enum\ActorType;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationLevel;
use EruoFood\Verification\Domain\Exception\VerificationInvalidState;
use EruoFood\Verification\Domain\Exception\VerificationNotFound;
use EruoFood\Verification\Domain\VerificationCase\CaseRepository;
use EruoFood\Verification\Domain\VerificationCase\VerificationCase;

/**
 * KYB for restaurants and groceries alike.
 *
 * Business verification is genuinely two questions, and this service keeps them
 * distinct because they fail in different ways and need different remedies:
 *
 * 1. **Does the company exist and is it in good standing?** Answered by the
 *    country's registry — CAC in Nigeria — through
 *    {@see BusinessRegistryRegistry}. A country with no registry integration
 *    routes to human review; it never defaults to "fine".
 * 2. **Is the person operating the account authorised to?** Answered by an
 *    ordinary identity case against the representative, using the same provider
 *    riders go through.
 *
 * A business is verified only when both hold. Checking a registration number
 * alone would verify that a company exists somewhere — not that the person
 * holding the account has anything to do with it.
 *
 * Restaurants (Marketplace) and groceries (Commerce) share this service and keep
 * their separate catalogues; only the KYB question is common, and it is answered
 * once here rather than implemented twice.
 */
final readonly class BusinessVerificationService
{
    public function __construct(
        private BusinessProfileRepository $profiles,
        private CaseRepository $cases,
        private BusinessRegistryRegistry $registries,
        private VerificationService $verification,
        private TransactionManager $transactions,
        private Clock $clock,
    ) {
    }

    /**
     * Record or update a business's registered identity.
     *
     * @param array<string, mixed> $address
     */
    public function registerProfile(
        string $businessKind,
        string $businessId,
        string $countryCode,
        string $registeredName,
        string $tradingName,
        string $businessType,
        string $registrationNumber,
        array $address,
        ?float $latitude = null,
        ?float $longitude = null,
    ): BusinessProfile {
        $country = strtoupper($countryCode);
        $registry = $this->registries->forCountry($country);

        // Reject an obviously malformed number before it reaches a reviewer or a
        // paid registry lookup — but only where we actually know the format.
        if ($registry !== null && ! $registry->isWellFormed($registrationNumber)) {
            throw new VerificationInvalidState(sprintf(
                'The registration number is not valid for %s (%s).',
                $country,
                $registry->authority(),
            ));
        }

        $authority = $registry?->authority() ?? 'UNKNOWN';

        return $this->transactions->atomic(function () use (
            $businessKind,
            $businessId,
            $country,
            $registeredName,
            $tradingName,
            $businessType,
            $registrationNumber,
            $authority,
            $address,
            $latitude,
            $longitude
        ): BusinessProfile {
            $now = $this->clock->now();
            $existing = $this->profiles->findForBusiness($businessKind, $businessId);

            if ($existing !== null) {
                $existing->updateProfile($registeredName, $tradingName, $businessType, $address, $latitude, $longitude, $now);
                $this->profiles->save($existing);

                return $existing;
            }

            $profile = BusinessProfile::register(
                id: $this->profiles->nextIdentity(),
                businessKind: $businessKind,
                businessId: $businessId,
                countryCode: $country,
                registeredName: $registeredName,
                tradingName: $tradingName,
                businessType: $businessType,
                // The domain holds it as plain text; the repository encrypts it.
                registrationNumber: $registrationNumber,
                registrationAuthority: $authority,
                address: $address,
                latitude: $latitude,
                longitude: $longitude,
                now: $now,
            );
            $this->profiles->save($profile);

            return $profile;
        });
    }

    /** Nominate someone authorised to act for the business. */
    public function addRepresentative(
        string $businessProfileId,
        string $userId,
        string $fullName,
        string $role,
        bool $isPrimary = true,
        ?float $ownershipPercentage = null,
    ): BusinessRepresentative {
        $profile = $this->profiles->findById($businessProfileId)
            ?? throw VerificationNotFound::of('business profile', $businessProfileId);

        return $this->transactions->atomic(function () use ($profile, $userId, $fullName, $role, $isPrimary, $ownershipPercentage): BusinessRepresentative {
            $representative = BusinessRepresentative::nominate(
                id: $this->profiles->nextRepresentativeIdentity(),
                businessProfileId: $profile->id(),
                userId: $userId,
                fullName: $fullName,
                role: $role,
                isPrimary: $isPrimary,
                ownershipPercentage: $ownershipPercentage,
                now: $this->clock->now(),
            );
            $this->profiles->saveRepresentative($representative);

            return $representative;
        });
    }

    /**
     * Start the representative's identity check.
     *
     * Deliberately a normal identity case against the *person*, not a field on
     * the business: the same machinery, queue and audit trail that verifies a
     * rider verifies a company director.
     */
    public function startRepresentativeVerification(string $representativeId): VerificationCase
    {
        $representative = $this->profiles->findRepresentative($representativeId)
            ?? throw VerificationNotFound::of('business representative', $representativeId);

        $profile = $this->profiles->findById($representative->businessProfileId())
            ?? throw VerificationNotFound::of('business profile', $representative->businessProfileId());

        $case = $this->verification->openCase(
            subjectType: SubjectType::Customer,
            subjectId: $representative->userId(),
            caseType: CaseType::Identity,
            countryCode: $profile->countryCode(),
            requestedLevel: VerificationLevel::Identity,
        );

        $started = $this->verification->startVerification(
            $case->id(),
            ['document', 'liveness', 'face_match'],
            ActorType::Subject,
            $representative->userId(),
        );

        $this->transactions->atomic(function () use ($representative, $started): void {
            $representative->attachIdentityCase($started->id(), $this->clock->now());
            $this->profiles->saveRepresentative($representative);
        });

        return $started;
    }

    /**
     * Run the registry check and open/settle the business case accordingly.
     *
     * The three outcomes map to genuinely different situations, and conflating
     * them would strip a reviewer of the information they need:
     *
     * - satisfactory        → verified
     * - needs manual review → queued, with the registry's note attached
     * - definitively bad    → rejected, with a specific reason
     */
    public function verifyRegistration(string $businessProfileId): VerificationCase
    {
        $profile = $this->profiles->findById($businessProfileId)
            ?? throw VerificationNotFound::of('business profile', $businessProfileId);

        $case = $this->verification->openCase(
            subjectType: SubjectType::Business,
            subjectId: $profile->businessId(),
            caseType: CaseType::Business,
            countryCode: $profile->countryCode(),
        );

        $registry = $this->registries->forCountry($profile->countryCode());

        // No registry for this market: honest manual review, not an assumption.
        if ($registry === null) {
            return $this->settle(
                $case->id(),
                new RegistryLookup(
                    found: false,
                    active: false,
                    matched: false,
                    requiresManualReview: true,
                    note: sprintf('No business registry is integrated for country "%s"; a reviewer must confirm.', $profile->countryCode()),
                ),
                $profile,
            );
        }

        // Outside any transaction — this is a network call.
        $lookup = $registry->lookup($profile->registrationNumber(), $profile->registeredName());

        return $this->settle($case->id(), $lookup, $profile);
    }

    /** Attach the business case id to the profile once it exists. */
    private function settle(string $caseId, RegistryLookup $lookup, BusinessProfile $profile): VerificationCase
    {
        $case = $this->transactions->atomic(function () use ($caseId, $lookup, $profile): VerificationCase {
            $locked = $this->cases->findByIdForUpdate($caseId)
                ?? throw VerificationNotFound::of('verification case', $caseId);

            $now = $this->clock->now();

            // The registry answers immediately, so the case passes through
            // Pending to a verdict rather than waiting on a subject.
            if ($locked->status() === \EruoFood\Verification\Domain\Enum\VerificationStatus::NotStarted) {
                $locked->startAttempt(
                    \EruoFood\Verification\Domain\Enum\ProviderName::Cac,
                    'registry_'.hash('sha256', $profile->id()),
                    ActorType::System,
                    null,
                    $now,
                );
            }

            match (true) {
                $lookup->isSatisfactory() => $locked->approve(
                    ActorType::System,
                    null,
                    null,
                    $now,
                    sprintf('Registry confirmed %s as active.', $lookup->registrationNumber ?? 'the registration'),
                ),
                $lookup->requiresManualReview => $locked->flagForReview(
                    ActorType::System,
                    null,
                    $now,
                    $lookup->note,
                ),
                default => $locked->reject(
                    $this->reasonFor($lookup),
                    ActorType::System,
                    null,
                    $now,
                    $lookup->note,
                ),
            };

            $this->cases->save($locked);

            $profile->attachVerificationCase($locked->id(), $now);
            $this->profiles->save($profile);

            return $locked;
        });

        $this->verification->announce($case);

        return $case;
    }

    /** Turn a registry outcome into a reason a reviewer and a merchant can both act on. */
    private function reasonFor(RegistryLookup $lookup): RejectionReason
    {
        return match (true) {
            ! $lookup->found => RejectionReason::RegistrationNotFound,
            ! $lookup->active => RejectionReason::RegistrationInactive,
            ! $lookup->matched => RejectionReason::RegistrationNameMismatch,
            default => RejectionReason::RegistrationNumberInvalid,
        };
    }

    public function profileFor(string $businessKind, string $businessId): ?BusinessProfile
    {
        return $this->profiles->findForBusiness($businessKind, $businessId);
    }

    public function profileById(string $profileId): ?BusinessProfile
    {
        return $this->profiles->findById($profileId);
    }

    public function representativeById(string $representativeId): ?BusinessRepresentative
    {
        return $this->profiles->findRepresentative($representativeId);
    }

    /** @return list<BusinessRepresentative> */
    public function representativesFor(string $businessProfileId): array
    {
        return $this->profiles->representativesFor($businessProfileId);
    }
}
