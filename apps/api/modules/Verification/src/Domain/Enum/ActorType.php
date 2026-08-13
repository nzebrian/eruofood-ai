<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Enum;

/** Who caused a change to a verification case. Recorded on every audit event. */
enum ActorType: string
{
    /** The platform itself — expiry sweeps, reconciliation, policy changes. */
    case System = 'system';

    /** An external verification provider, via a signed webhook or a polled decision. */
    case Provider = 'provider';

    /** A back-office reviewer acting under a permission. */
    case Admin = 'admin';

    /** The subject of the case (starting or restarting their own verification). */
    case Subject = 'subject';
}
