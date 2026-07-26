<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Service;

use EruoFood\Ai\Domain\Usage\AiUsageLogRepository;
use EruoFood\Ai\Domain\Usage\UsageSummary;

/** Read-side for a user's AI usage & cost, powering the "AI settings" screen. */
final readonly class AiUsageService
{
    public function __construct(private AiUsageLogRepository $usage)
    {
    }

    public function summaryForUser(string $userId, int $sinceDays = 30): UsageSummary
    {
        return $this->usage->summaryForUser($userId, max(1, min(365, $sinceDays)));
    }
}
