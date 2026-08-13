<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Enum;

/**
 * The verification providers the platform can route to.
 *
 * The domain names them but never depends on how any of them works; each has an
 * adapter in Infrastructure behind a provider-neutral port.
 */
enum ProviderName: string
{
    /** Didit — identity verification (document, licence, liveness, face match). */
    case Didit = 'didit';

    /** Nigeria's Corporate Affairs Commission business registry. */
    case Cac = 'cac';

    /** Human review, for anything no registry or provider can settle. */
    case Manual = 'manual';

    /** Deterministic and offline. Forced in testing so the suite never leaves the process. */
    case Mock = 'mock';
}
