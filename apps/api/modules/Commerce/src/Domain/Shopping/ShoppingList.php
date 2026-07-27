<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Shopping;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A smart shopping list — a named, checkable list of grocery lines a user
 * maintains (and that the AI assistant can populate). Each line is free-text
 * with a quantity and a bought flag, optionally soft-linked to a product.
 *
 * @phpstan-type Line array{name: string, quantity: int, product_id: string|null, bought: bool}
 */
final class ShoppingList
{
    /**
     * @param list<array{name: string, quantity: int, product_id: string|null, bought: bool}> $lines
     */
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private string $name,
        private array $lines,
    ) {
    }

    public static function create(string $id, string $userId, string $name): self
    {
        return new self($id, $userId, $name, []);
    }

    /**
     * @param list<array{name: string, quantity: int, product_id: string|null, bought: bool}> $lines
     */
    public static function reconstitute(string $id, string $userId, string $name, array $lines): self
    {
        return new self($id, $userId, $name, array_values($lines));
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function addLine(string $name, int $quantity = 1, ?string $productId = null): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Shopping list line cannot be empty.');
        }
        $this->lines[] = [
            'name' => $name,
            'quantity' => max(1, $quantity),
            'product_id' => $productId,
            'bought' => false,
        ];
    }

    public function toggleBought(int $index, bool $bought): void
    {
        if (! isset($this->lines[$index])) {
            throw new InvalidArgumentException('No such shopping list line.');
        }
        $this->lines[$index]['bought'] = $bought;
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<array{name: string, quantity: int, product_id: string|null, bought: bool}> */
    public function lines(): array
    {
        return $this->lines;
    }
}
