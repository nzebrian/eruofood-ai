<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\ValueObject;

/** One point in a metric time series: a bucket label and its numeric value. */
final readonly class DataPoint
{
    public function __construct(
        public string $bucket,
        public int $value,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['bucket' => $this->bucket, 'value' => $this->value];
    }
}
