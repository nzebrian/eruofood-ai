<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Enum;

/**
 * Why a verification failed, in EruoFood's vocabulary rather than any
 * provider's.
 *
 * Each adapter maps its own failure codes onto this set, so support staff, the
 * review queue and the subject-facing message all read the same terms no matter
 * which provider decided. Anything unrecognised becomes {@see ProviderError}
 * rather than being guessed at.
 */
enum RejectionReason: string
{
    case DocumentExpired = 'document_expired';
    case DocumentUnreadable = 'document_unreadable';
    case DocumentUnsupported = 'document_unsupported';
    case DocumentTampered = 'document_tampered';
    case FaceMismatch = 'face_mismatch';
    case LivenessFailed = 'liveness_failed';
    case DataMismatch = 'data_mismatch';
    case DuplicateIdentity = 'duplicate_identity';
    case UnderageSubject = 'underage_subject';
    case SanctionsHit = 'sanctions_hit';
    case AbandonedBySubject = 'abandoned_by_subject';

    // Business-side reasons.
    case RegistrationNotFound = 'registration_not_found';
    case RegistrationInactive = 'registration_inactive';
    case RegistrationNameMismatch = 'registration_name_mismatch';
    case RegistrationNumberInvalid = 'registration_number_invalid';
    case RepresentativeUnverified = 'representative_unverified';

    case ManualRejection = 'manual_rejection';
    case ProviderError = 'provider_error';

    /** Whether the subject can fix this and try again. */
    public function isRetryable(): bool
    {
        return ! in_array($this, [
            self::SanctionsHit,
            self::DuplicateIdentity,
            self::UnderageSubject,
        ], true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
