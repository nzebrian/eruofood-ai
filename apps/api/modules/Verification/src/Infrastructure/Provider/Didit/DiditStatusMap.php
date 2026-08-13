<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Provider\Didit;

use EruoFood\Verification\Domain\Enum\DocumentType;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\VerificationStatus;

/**
 * Translates Didit's vocabulary into EruoFood's.
 *
 * Kept as its own class so the provider's terms exist in exactly one file. When
 * Didit adds a status or renames a reason, nothing outside this map changes.
 *
 * Two deliberate choices about unknown values:
 *
 * 1. An unrecognised status maps to {@see VerificationStatus::RequiresReview},
 *    never to Verified. A provider vocabulary change must send a case to a human,
 *    not silently approve a rider.
 * 2. An unrecognised failure reason maps to {@see RejectionReason::ProviderError}
 *    and the raw string is preserved on the attempt, so support can explain the
 *    case instead of guessing.
 *
 * Didit publishes its statuses in title case with spaces ("In Review") while its
 * API reference shows upper snake ("IN_REVIEW"); both appear in their material,
 * so matching is done on a normalised key that accepts either.
 */
final class DiditStatusMap
{
    /**
     * Map a provider status string to ours.
     *
     * @return VerificationStatus RequiresReview for anything unrecognised
     */
    public static function status(string $raw): VerificationStatus
    {
        return match (self::normalise($raw)) {
            'not_started' => VerificationStatus::Pending,
            'in_progress', 'pending' => VerificationStatus::Processing,
            'in_review' => VerificationStatus::RequiresReview,
            'approved' => VerificationStatus::Verified,
            'declined' => VerificationStatus::Rejected,
            'expired', 'kyc_expired' => VerificationStatus::Expired,
            'abandoned' => VerificationStatus::Rejected,
            'resubmitted' => VerificationStatus::ReverificationRequired,
            default => VerificationStatus::RequiresReview,
        };
    }

    /** Whether a status string is one we actually recognise. */
    public static function isKnownStatus(string $raw): bool
    {
        return in_array(self::normalise($raw), [
            'not_started', 'in_progress', 'pending', 'in_review', 'approved',
            'declined', 'expired', 'kyc_expired', 'abandoned', 'resubmitted',
        ], true);
    }

    /**
     * Derive a rejection reason from the decision payload.
     *
     * Didit reports per-check outcomes (`kyc`, `liveness`, `face_match`, `aml`)
     * alongside the overall status, so the most specific failing check gives a
     * far more useful reason than the top-level "Declined" alone. Checked in
     * order of how actionable the answer is for the subject.
     *
     * @param array<string, mixed> $payload
     */
    public static function reason(string $rawStatus, array $payload): ?RejectionReason
    {
        $status = self::normalise($rawStatus);

        if ($status === 'abandoned') {
            return RejectionReason::AbandonedBySubject;
        }
        if ($status === 'expired' || $status === 'kyc_expired') {
            return RejectionReason::DocumentExpired;
        }
        if ($status !== 'declined') {
            return null;
        }

        if (self::checkFailed($payload, 'aml')) {
            return RejectionReason::SanctionsHit;
        }
        if (self::checkFailed($payload, 'liveness')) {
            return RejectionReason::LivenessFailed;
        }
        if (self::checkFailed($payload, 'face_match')) {
            return RejectionReason::FaceMismatch;
        }

        if (self::checkFailed($payload, 'kyc')) {
            $warning = self::kycWarning($payload);

            return match (true) {
                str_contains($warning, 'expire') => RejectionReason::DocumentExpired,
                str_contains($warning, 'unsupported') => RejectionReason::DocumentUnsupported,
                str_contains($warning, 'tamper'), str_contains($warning, 'forg') => RejectionReason::DocumentTampered,
                str_contains($warning, 'blur'), str_contains($warning, 'readab'), str_contains($warning, 'quality') => RejectionReason::DocumentUnreadable,
                str_contains($warning, 'duplicate') => RejectionReason::DuplicateIdentity,
                str_contains($warning, 'age'), str_contains($warning, 'minor') => RejectionReason::UnderageSubject,
                str_contains($warning, 'mismatch') => RejectionReason::DataMismatch,
                default => RejectionReason::DocumentUnreadable,
            };
        }

        return RejectionReason::ProviderError;
    }

    /** Map Didit's document type string onto ours. */
    public static function documentType(?string $raw): DocumentType
    {
        $key = self::normalise((string) $raw);

        return match (true) {
            $key === '' => DocumentType::Other,
            str_contains($key, 'passport') => DocumentType::Passport,
            str_contains($key, 'driving'), str_contains($key, 'driver') => DocumentType::DriversLicence,
            str_contains($key, 'residence') => DocumentType::ResidencePermit,
            str_contains($key, 'voter') => DocumentType::VotersCard,
            str_contains($key, 'identity'), str_contains($key, 'national'), $key === 'id_card' => DocumentType::NationalId,
            default => DocumentType::Other,
        };
    }

    /** @param array<string, mixed> $payload */
    private static function checkFailed(array $payload, string $check): bool
    {
        $block = $payload[$check] ?? null;
        if (! is_array($block)) {
            return false;
        }

        $status = self::normalise((string) ($block['status'] ?? ''));

        return $status !== '' && ! in_array($status, ['approved', 'passed', 'success', 'not_applicable', 'skipped'], true);
    }

    /** @param array<string, mixed> $payload */
    private static function kycWarning(array $payload): string
    {
        $block = $payload['kyc'] ?? null;
        if (! is_array($block)) {
            return '';
        }

        $parts = [];
        foreach (['warning', 'reason', 'message', 'decline_reason'] as $key) {
            if (isset($block[$key]) && is_scalar($block[$key])) {
                $parts[] = (string) $block[$key];
            }
        }

        if (isset($block['warnings']) && is_array($block['warnings'])) {
            foreach ($block['warnings'] as $warning) {
                if (is_scalar($warning)) {
                    $parts[] = (string) $warning;
                } elseif (is_array($warning) && isset($warning['risk']) && is_scalar($warning['risk'])) {
                    $parts[] = (string) $warning['risk'];
                }
            }
        }

        return strtolower(implode(' ', $parts));
    }

    /** Fold "In Review", "IN_REVIEW" and "in review" onto one key. */
    private static function normalise(string $raw): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($raw)));
    }
}
