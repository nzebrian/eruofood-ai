<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Operations;

/** What a vendor/restaurant approval request is for. */
enum ApprovalKind: string
{
    case Onboarding = 'onboarding';           // new vendor/restaurant joining
    case BusinessVerification = 'business_verification'; // KYB / documents
    case Compliance = 'compliance';           // periodic compliance check
}
