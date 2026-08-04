<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * Raised when a calculation or personalisation needs a health profile the user
 * has not set up yet. Maps to 422 so clients can prompt "complete your profile".
 */
final class ProfileNotConfigured extends DomainException
{
    public function __construct()
    {
        parent::__construct('A health profile is required for this action. Please set up your profile first.');
    }

    public function errorCode(): string
    {
        return 'NUTRITION_PROFILE_INCOMPLETE';
    }
}
