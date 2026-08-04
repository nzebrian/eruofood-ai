<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Shopping;

/** Persistence port for the {@see ShoppingList} aggregate. */
interface ShoppingListRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?ShoppingList;

    /** @return list<ShoppingList> */
    public function forUser(string $userId): array;

    public function save(ShoppingList $list): void;

    public function delete(string $id): void;
}
