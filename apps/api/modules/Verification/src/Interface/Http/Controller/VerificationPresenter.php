<?php

declare(strict_types=1);

namespace EruoFood\Verification\Interface\Http\Controller;

use EruoFood\Verification\Domain\Business\BusinessProfile;
use EruoFood\Verification\Domain\Business\BusinessRepresentative;
use EruoFood\Verification\Domain\VerificationCase\StatusChange;
use EruoFood\Verification\Domain\VerificationCase\VerificationCase;

/**
 * Shapes verification data for the API — and decides what never leaves.
 *
 * There are two views of a case on purpose. {@see subjectView()} is what the
 * person being verified sees: enough to know where they stand and what to do
 * next, with no provider reference (which would let them poke at the provider
 * session directly) and no internal note. {@see reviewerView()} adds the
 * operational fields a reviewer needs.
 *
 * Neither returns identity data. Names, document numbers and dates of birth are
 * only ever served by {@see \EruoFood\Verification\Application\Service\ReviewService::sensitiveDocuments()},
 * behind its own permission and its own audit event.
 */
final class VerificationPresenter
{
    /** @return array<string, mixed> */
    public function subjectView(VerificationCase $case): array
    {
        return [
            'id' => $case->id(),
            'subject_type' => $case->subjectType()->value,
            'case_type' => $case->caseType()->value,
            'status' => $case->status()->value,
            'status_label' => $case->status()->label(),
            'requested_level' => $case->requestedLevel()->value,
            // The reason is shown so a subject can fix the problem, along with
            // whether trying again is worth their time.
            'reason_code' => $case->rejectionReason()?->value,
            'reason_label' => $case->rejectionReason()?->label(),
            'retryable' => $case->rejectionReason()?->isRetryable(),
            'verified_at' => $case->verifiedAt()?->format(DATE_ATOM),
            'expires_at' => $case->expiresAt()?->format(DATE_ATOM),
            'created_at' => $case->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function reviewerView(VerificationCase $case): array
    {
        return $this->subjectView($case) + [
            'subject_id' => $case->subjectId(),
            'country_code' => $case->countryCode(),
            'provider' => $case->provider()?->value,
            'review_note' => $case->reviewNote(),
            'updated_at' => $case->updatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function statusChange(StatusChange $change): array
    {
        return [
            'from' => $change->from->value,
            'to' => $change->to->value,
            'actor_type' => $change->actorType->value,
            'actor_id' => $change->actorId,
            'reason_code' => $change->reasonCode,
            'note' => $change->note,
            'occurred_at' => $change->occurredAt->format(DATE_ATOM),
        ];
    }

    /**
     * The business profile as a merchant sees it.
     *
     * The registration number is masked: a merchant already knows their own
     * number, and echoing it in full turns any leaked response — a log, a
     * screenshot, a shared debugging session — into a disclosure.
     *
     * @return array<string, mixed>
     */
    public function businessProfile(BusinessProfile $profile): array
    {
        return [
            'id' => $profile->id(),
            'business_kind' => $profile->businessKind(),
            'business_id' => $profile->businessId(),
            'country_code' => $profile->countryCode(),
            'registered_name' => $profile->registeredName(),
            'trading_name' => $profile->tradingName(),
            'business_type' => $profile->businessType(),
            'registration_number_masked' => $this->maskRegistration($profile->registrationNumber()),
            'registration_authority' => $profile->registrationAuthority(),
            'address' => $profile->address(),
            'latitude' => $profile->latitude(),
            'longitude' => $profile->longitude(),
            'identity_case_id' => $profile->identityCaseId(),
            'created_at' => $profile->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function representative(BusinessRepresentative $representative): array
    {
        return [
            'id' => $representative->id(),
            'user_id' => $representative->userId(),
            'full_name' => $representative->fullName(),
            'role' => $representative->role(),
            'is_primary' => $representative->isPrimary(),
            'identity_case_id' => $representative->identityCaseId(),
            'ownership_percentage' => $representative->ownershipPercentage(),
        ];
    }

    private function maskRegistration(string $number): string
    {
        if ($number === '') {
            return '';
        }

        return strlen($number) <= 4
            ? str_repeat('*', strlen($number))
            : str_repeat('*', strlen($number) - 4).substr($number, -4);
    }
}
