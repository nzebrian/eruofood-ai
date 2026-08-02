<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use DateTimeImmutable;
use EruoFood\PublicApi\Application\Port\QuotaStore;

/**
 * Daily and monthly request quotas per client. Each request increments both
 * period buckets; the request is allowed only while both remain within their
 * limits. Also exposes current usage for the developer portal.
 */
final readonly class QuotaService
{
    public function __construct(
        private QuotaStore $store,
        private int $dailyLimit,
        private int $monthlyLimit,
    ) {
    }

    /**
     * @return array{allowed: bool, exceeded_period: string|null, daily_used: int, daily_limit: int, monthly_used: int, monthly_limit: int}
     */
    public function consume(string $applicationId): array
    {
        $now = new DateTimeImmutable();
        $daily = $this->store->increment($this->dailyKey($applicationId, $now), 2 * 86400);
        $monthly = $this->store->increment($this->monthlyKey($applicationId, $now), 35 * 86400);

        $exceeded = null;
        if ($daily > $this->dailyLimit) {
            $exceeded = 'daily';
        } elseif ($monthly > $this->monthlyLimit) {
            $exceeded = 'monthly';
        }

        return [
            'allowed' => $exceeded === null,
            'exceeded_period' => $exceeded,
            'daily_used' => $daily,
            'daily_limit' => $this->dailyLimit,
            'monthly_used' => $monthly,
            'monthly_limit' => $this->monthlyLimit,
        ];
    }

    /**
     * @return array{daily_used: int, daily_limit: int, monthly_used: int, monthly_limit: int}
     */
    public function usage(string $applicationId): array
    {
        $now = new DateTimeImmutable();

        return [
            'daily_used' => $this->store->current($this->dailyKey($applicationId, $now)),
            'daily_limit' => $this->dailyLimit,
            'monthly_used' => $this->store->current($this->monthlyKey($applicationId, $now)),
            'monthly_limit' => $this->monthlyLimit,
        ];
    }

    private function dailyKey(string $applicationId, DateTimeImmutable $now): string
    {
        return sprintf('publicapi:q:%s:d:%s', $applicationId, $now->format('Ymd'));
    }

    private function monthlyKey(string $applicationId, DateTimeImmutable $now): string
    {
        return sprintf('publicapi:q:%s:m:%s', $applicationId, $now->format('Ym'));
    }
}
