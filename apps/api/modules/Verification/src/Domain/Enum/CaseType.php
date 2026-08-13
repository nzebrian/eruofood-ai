<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Enum;

/**
 * What kind of check a case performs.
 *
 * Identity asks "is this person who they claim". Business asks "does this
 * company exist and is it active". They use different providers, different
 * evidence and different reviewers, so they are separate case types even though
 * a business onboarding normally needs both.
 */
enum CaseType: string
{
    case Identity = 'identity';
    case Business = 'business';
}
