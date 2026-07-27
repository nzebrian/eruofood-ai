<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Inventory;

use EruoFood\Commerce\Domain\ValueObject\Address;

/** A physical stock location. */
final class Warehouse
{
    private function __construct(
        private readonly string $id,
        private string $name,
        private ?string $code,
        private ?Address $address,
    ) {
    }

    public static function create(string $id, string $name, ?string $code, ?Address $address): self
    {
        return new self($id, $name, $code, $address);
    }

    public static function reconstitute(string $id, string $name, ?string $code, ?Address $address): self
    {
        return new self($id, $name, $code, $address);
    }

    public function rename(string $name, ?string $code, ?Address $address): void
    {
        $this->name = $name;
        $this->code = $code;
        $this->address = $address;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function code(): ?string
    {
        return $this->code;
    }

    public function address(): ?Address
    {
        return $this->address;
    }
}
