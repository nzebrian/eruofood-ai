<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Usage;

use DateTimeImmutable;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\ValueObject\TokenUsage;

/**
 * An immutable ledger entry recording one AI call — the backbone of AI Usage
 * Logging and AI Cost Tracking.
 *
 * Every gateway call (cache hit or live provider call, success or failure)
 * produces exactly one of these, attributing tokens, dollar cost and latency to
 * a user and feature for analytics, quota enforcement and billing.
 */
final readonly class AiUsageLog
{
    public function __construct(
        private string $id,
        private ?string $userId,
        private AiFeature $feature,
        private AiProviderName $provider,
        private string $model,
        private TokenUsage $tokens,
        private float $costUsd,
        private bool $cached,
        private int $latencyMs,
        private bool $success,
        private ?string $errorCode,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function feature(): AiFeature
    {
        return $this->feature;
    }

    public function provider(): AiProviderName
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function tokens(): TokenUsage
    {
        return $this->tokens;
    }

    public function costUsd(): float
    {
        return $this->costUsd;
    }

    public function wasCached(): bool
    {
        return $this->cached;
    }

    public function latencyMs(): int
    {
        return $this->latencyMs;
    }

    public function wasSuccessful(): bool
    {
        return $this->success;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
