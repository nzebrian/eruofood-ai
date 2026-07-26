<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Weekly opening hours: a map of weekday (0=Sunday…6=Saturday) to an
 * `{open, close}` HH:MM window, or absence = closed that day. Used to show a
 * vendor as open/closed and to gate ordering.
 */
final readonly class BusinessHours
{
    private const DAYS = [0, 1, 2, 3, 4, 5, 6];

    /** @param array<int, array{open: string, close: string}> $days */
    private function __construct(public array $days)
    {
    }

    /** @param array<int|string, array{open: string, close: string}> $days */
    public static function fromArray(array $days): self
    {
        $clean = [];
        foreach ($days as $day => $window) {
            $d = (int) $day;
            if (! in_array($d, self::DAYS, true)) {
                throw new InvalidArgumentException('Weekday must be 0-6.');
            }
            if (! self::isTime((string) ($window['open'] ?? '')) || ! self::isTime((string) ($window['close'] ?? ''))) {
                throw new InvalidArgumentException('Hours must be HH:MM.');
            }
            $clean[$d] = ['open' => (string) $window['open'], 'close' => (string) $window['close']];
        }
        ksort($clean);

        return new self($clean);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** Is the vendor open at the given day (0-6) and HH:MM time? */
    public function isOpenAt(int $weekday, string $time): bool
    {
        $window = $this->days[$weekday] ?? null;
        if ($window === null) {
            return false;
        }

        return $time >= $window['open'] && $time <= $window['close'];
    }

    /** @return array<int, array{open: string, close: string}> */
    public function toArray(): array
    {
        return $this->days;
    }

    private static function isTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
