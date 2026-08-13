<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Enum;

/**
 * The kind of document a verification was performed against.
 *
 * Stored as metadata only — never the document itself. Which types a provider
 * actually accepts is a provider-side workflow configuration, so this enum
 * describes what came back rather than constraining what may be submitted.
 */
enum DocumentType: string
{
    case Passport = 'passport';
    case NationalId = 'national_id';
    case DriversLicence = 'drivers_licence';
    case VotersCard = 'voters_card';
    case ResidencePermit = 'residence_permit';
    case Other = 'other';

    /** Whether this document evidences a right to drive. */
    public function provesDrivingEntitlement(): bool
    {
        return $this === self::DriversLicence;
    }
}
