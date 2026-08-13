<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\DTO;

use EruoFood\Verification\Domain\Enum\DocumentType;

/**
 * The reduced document facts an adapter is permitted to hand back.
 *
 * Note what is absent: no image, no file reference, no full document number.
 * The adapter performs the reduction — a provider payload may contain far more,
 * and it stops at the adapter boundary rather than flowing into the domain and
 * being stored by accident.
 */
final readonly class DocumentSummary
{
    public function __construct(
        public DocumentType $type,
        public ?string $issuingCountry = null,
        /** Full number as returned; reduced to last-4 before it is ever stored. */
        public ?string $documentNumber = null,
        public ?string $expiresOn = null,
    ) {
    }
}
