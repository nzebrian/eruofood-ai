<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\DTO;

use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\VerificationStatus;

/**
 * A provider's verdict, normalised.
 *
 * `rawStatus` is kept deliberately: when a provider introduces a status we have
 * not mapped, the mapped value fails closed to RequiresReview and the raw
 * string is what lets a human work out what actually happened.
 */
final readonly class VerificationDecision
{
    /** @param list<DocumentSummary> $documents */
    public function __construct(
        public VerificationStatus $status,
        public string $rawStatus,
        public ?RejectionReason $reason = null,
        public array $documents = [],
        public ?string $note = null,
    ) {
    }
}
