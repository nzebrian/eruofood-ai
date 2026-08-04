<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A product barcode (EAN-8/13, UPC-A). Kept as a normalised digit string —
 * architecture-ready for barcode scanning without pulling in a scanner library.
 */
final readonly class Barcode
{
    public string $value;

    public function __construct(string $value)
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (! in_array(strlen($digits), [8, 12, 13], true)) {
            throw new InvalidArgumentException('Barcode must be 8, 12 or 13 digits (EAN/UPC).');
        }
        $this->value = $digits;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
