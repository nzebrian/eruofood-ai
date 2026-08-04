<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Inventory;

/** A goods supplier — soft-referenced by inventory records for procurement. */
final class Supplier
{
    private function __construct(
        private readonly string $id,
        private string $name,
        private ?string $contactName,
        private ?string $email,
        private ?string $phone,
    ) {
    }

    public static function create(string $id, string $name, ?string $contactName, ?string $email, ?string $phone): self
    {
        return new self($id, $name, $contactName, $email, $phone);
    }

    public static function reconstitute(string $id, string $name, ?string $contactName, ?string $email, ?string $phone): self
    {
        return new self($id, $name, $contactName, $email, $phone);
    }

    public function update(string $name, ?string $contactName, ?string $email, ?string $phone): void
    {
        $this->name = $name;
        $this->contactName = $contactName;
        $this->email = $email;
        $this->phone = $phone;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function contactName(): ?string
    {
        return $this->contactName;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }
}
