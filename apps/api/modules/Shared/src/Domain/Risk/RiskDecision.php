<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Risk;

/** What to do about an assessed action. */
enum RiskDecision: string
{
    case Allow = 'allow';

    /** Proceed, and flag it for a human. The common case for real detectors. */
    case Review = 'review';

    case Block = 'block';
}
