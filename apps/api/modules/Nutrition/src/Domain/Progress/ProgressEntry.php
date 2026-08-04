<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Progress;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/** A dated body-weight measurement used for progress tracking. */
final class ProgressEntry
{
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private readonly string $date, // Y-m-d
        private readonly float $weightKg,
        private readonly ?string $note,
        private readonly DateTimeImmutable $recordedAt,
    ) {
    }

    public static function create(
        string $id,
        string $userId,
        string $date,
        float $weightKg,
        ?string $note,
        DateTimeImmutable $recordedAt,
    ): self {
        if ($weightKg < 20 || $weightKg > 500) {
            throw new InvalidArgumentException('Weight must be between 20 and 500 kg.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('Progress date must be an ISO Y-m-d string.');
        }

        return new self($id, $userId, $date, $weightKg, $note, $recordedAt);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function date(): string
    {
        return $this->date;
    }

    public function weightKg(): float
    {
        return $this->weightKg;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
