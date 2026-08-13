<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Document;

use DateTimeImmutable;
use EruoFood\Verification\Domain\Enum\DocumentType;

/**
 * What EruoFood keeps about a verified document — and deliberately nothing more.
 *
 * The platform never stores the document itself. There is no image, no file
 * path and no blob anywhere in this context; the provider holds the artefact
 * and we hold a reference to their session. What remains here is the minimum
 * needed to answer operational and audit questions: was the document of an
 * accepted type, was it valid at the time, does it evidence a right to drive,
 * and which provider session decided.
 *
 * Even the document number is reduced to its last four characters, which is
 * enough to reconcile a support call and useless to an attacker. It is
 * encrypted at rest on top of that.
 */
final readonly class DocumentMetadata
{
    public function __construct(
        public string $id,
        public string $caseId,
        public DocumentType $documentType,
        public ?string $issuingCountry,
        /** Last four characters only — never the full number. */
        public ?string $numberLast4,
        public ?DateTimeImmutable $expiresOn,
        public ?string $providerReference,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Reduce a full document number to the fragment we are willing to keep.
     *
     * Callers pass whatever the provider returned; this is the only way a
     * number enters the system, so there is no path by which a full number
     * reaches storage.
     */
    public static function lastFourOf(?string $documentNumber): ?string
    {
        if ($documentNumber === null) {
            return null;
        }

        $trimmed = preg_replace('/\s+/', '', $documentNumber) ?? '';

        return $trimmed === '' ? null : mb_substr($trimmed, -4);
    }

    public function isExpiredAt(DateTimeImmutable $moment): bool
    {
        return $this->expiresOn !== null && $this->expiresOn <= $moment;
    }
}
