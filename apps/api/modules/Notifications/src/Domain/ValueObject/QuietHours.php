<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\ValueObject;

use DateTimeImmutable;

/**
 * A daily quiet-hours window (local HH:MM). During the window, quiet-hours
 * respecting categories defer; transactional and high-priority notifications
 * are unaffected. Supports windows that cross midnight.
 */
final readonly class QuietHours
{
    public function __construct(
        public bool $enabled,
        public string $start, // HH:MM
        public string $end,   // HH:MM
    ) {
    }

    public static function disabled(): self
    {
        return new self(false, '22:00', '07:00');
    }

    public function isWithin(DateTimeImmutable $now): bool
    {
        if (! $this->enabled) {
            return false;
        }
        $minutes = (int) $now->format('G') * 60 + (int) $now->format('i');
        $start = $this->toMinutes($this->start);
        $end = $this->toMinutes($this->end);

        return $start <= $end
            ? ($minutes >= $start && $minutes < $end)
            : ($minutes >= $start || $minutes < $end); // crosses midnight
    }

    private function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['enabled'] ?? false),
            (string) ($data['start'] ?? '22:00'),
            (string) ($data['end'] ?? '07:00'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['enabled' => $this->enabled, 'start' => $this->start, 'end' => $this->end];
    }
}
