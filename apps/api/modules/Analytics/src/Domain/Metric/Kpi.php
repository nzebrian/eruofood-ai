<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Metric;

/**
 * A single computed key performance indicator: its current value, unit, and the
 * percentage change versus the previous comparable period (for trend arrows).
 */
final readonly class Kpi
{
    public function __construct(
        public string $key,
        public string $label,
        public int $value,
        public string $unit, // count|money|tokens
        public ?float $deltaPct,
    ) {
    }

    public static function withDelta(string $key, string $label, int $value, int $previous, string $unit): self
    {
        $delta = $previous > 0 ? round((($value - $previous) / $previous) * 100, 1) : null;

        return new self($key, $label, $value, $unit, $delta);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'unit' => $this->unit,
            'delta_pct' => $this->deltaPct,
        ];
    }
}
