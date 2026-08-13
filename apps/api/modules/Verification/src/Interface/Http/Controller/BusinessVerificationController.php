<?php

declare(strict_types=1);

namespace EruoFood\Verification\Interface\Http\Controller;

use EruoFood\Verification\Application\Service\BusinessVerificationService;
use EruoFood\Verification\Application\Service\VerificationService;
use EruoFood\Verification\Domain\Business\BusinessProfile;
use EruoFood\Verification\Domain\Business\BusinessRepresentative;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Exception\VerificationNotAuthorized;
use EruoFood\Verification\Domain\Exception\VerificationNotFound;
use EruoFood\Verification\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Verification\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * KYB for restaurants and groceries.
 *
 * The same endpoints serve both, distinguished by `business_kind`, because the
 * question is identical even though the catalogues are separate. M24 does not
 * merge Marketplace and Commerce; it shares only the verification.
 *
 * Ownership is checked through the owning context rather than trusted from the
 * request: a merchant may only submit KYB for a business they actually own, and
 * that fact lives in Marketplace or Commerce, not here. The check is injected as
 * a callable so this controller does not import either module.
 */
final readonly class BusinessVerificationController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    /** @param callable(string, string, string): bool $ownsBusiness (kind, businessId, userId) */
    public function __construct(
        private BusinessVerificationService $business,
        private VerificationService $verification,
        private VerificationPresenter $presenter,
        private mixed $ownsBusiness,
    ) {
    }

    /** Submit or update the registered identity of a business. */
    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_kind' => ['required', 'in:restaurant,grocery'],
            'business_id' => ['required', 'uuid'],
            'country_code' => ['required', 'string', 'size:2'],
            'registered_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'string', 'max:64'],
            'registration_number' => ['required', 'string', 'max:64'],
            'address' => ['required', 'array'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $this->assertOwnsBusiness($request, (string) $data['business_kind'], (string) $data['business_id']);

        $profile = $this->business->registerProfile(
            businessKind: (string) $data['business_kind'],
            businessId: (string) $data['business_id'],
            countryCode: (string) $data['country_code'],
            registeredName: (string) $data['registered_name'],
            tradingName: (string) $data['trading_name'],
            businessType: (string) $data['business_type'],
            registrationNumber: (string) $data['registration_number'],
            address: (array) $data['address'],
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
        );

        return $this->data($this->presenter->businessProfile($profile), 201);
    }

    /** Nominate the person authorised to act for the business. */
    public function addRepresentative(Request $request, string $profileId): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:64'],
            'is_primary' => ['sometimes', 'boolean'],
            'ownership_percentage' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $profile = $this->ownedProfile($request, $profileId);

        $representative = $this->business->addRepresentative(
            businessProfileId: $profile->id(),
            // The representative is the authenticated caller. Accepting a
            // user_id from the request would let a merchant nominate someone
            // else's verified identity as their own representative.
            userId: $this->currentUserId($request),
            fullName: (string) $data['full_name'],
            role: (string) $data['role'],
            isPrimary: (bool) ($data['is_primary'] ?? true),
            ownershipPercentage: isset($data['ownership_percentage']) ? (float) $data['ownership_percentage'] : null,
        );

        return $this->data($this->presenter->representative($representative), 201);
    }

    /** Run the registry check for the business. */
    public function verifyRegistration(Request $request, string $profileId): JsonResponse
    {
        $profile = $this->ownedProfile($request, $profileId);
        $case = $this->business->verifyRegistration($profile->id());

        return $this->data($this->presenter->subjectView($case), 201);
    }

    /** Start the representative's identity check. */
    public function verifyRepresentative(Request $request, string $representativeId): JsonResponse
    {
        $representative = $this->representativeOwnedBy($request, $representativeId);
        $case = $this->business->startRepresentativeVerification($representative->id());

        return $this->data($this->presenter->subjectView($case), 201);
    }

    /** The business's KYB standing, as its owner sees it. */
    public function status(Request $request, string $kind, string $businessId): JsonResponse
    {
        $this->assertOwnsBusiness($request, $kind, $businessId);

        $profile = $this->business->profileFor($kind, $businessId);
        $case = $this->verification->latestFor(SubjectType::Business, $businessId, CaseType::Business);

        return $this->data([
            'status' => $case?->status()->value ?? 'not_started',
            'eligible_to_trade' => $case?->status()->isVerified() ?? false,
            'profile' => $profile !== null ? $this->presenter->businessProfile($profile) : null,
            'case' => $case !== null ? $this->presenter->subjectView($case) : null,
            'representatives' => $profile === null ? [] : array_map(
                fn (BusinessRepresentative $r): array => $this->presenter->representative($r),
                $this->business->representativesFor($profile->id()),
            ),
        ]);
    }

    private function ownedProfile(Request $request, string $profileId): BusinessProfile
    {
        $profile = $this->business->profileById($profileId)
            ?? throw VerificationNotFound::of('business profile', $profileId);

        // Authorise against the business the profile belongs to, not against the
        // profile id itself — ownership is a fact the owning context holds.
        $this->assertOwnsBusiness($request, $profile->businessKind(), $profile->businessId());

        return $profile;
    }

    private function representativeOwnedBy(Request $request, string $representativeId): BusinessRepresentative
    {
        $representative = $this->business->representativeById($representativeId)
            ?? throw VerificationNotFound::of('business representative', $representativeId);

        // A representative may only start their own identity check.
        if ($representative->userId() !== $this->currentUserId($request)) {
            throw new VerificationNotAuthorized();
        }

        return $representative;
    }

    private function assertOwnsBusiness(Request $request, string $kind, string $businessId): void
    {
        $owns = ($this->ownsBusiness)($kind, $businessId, $this->currentUserId($request));

        if ($owns !== true) {
            throw new VerificationNotAuthorized();
        }
    }
}
