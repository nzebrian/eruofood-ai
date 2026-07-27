<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use EruoFood\Commerce\Application\Port\CommerceAdvisor;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;
use EruoFood\Commerce\Domain\Exception\NotResourceOwner;
use EruoFood\Commerce\Domain\Shopping\ShoppingList;
use EruoFood\Commerce\Domain\Shopping\ShoppingListRepository;

/** Smart shopping lists, including AI-assisted list building. */
final readonly class ShoppingListService
{
    public function __construct(
        private ShoppingListRepository $lists,
        private CommerceAdvisor $advisor,
    ) {
    }

    public function create(string $userId, string $name): ShoppingList
    {
        $list = ShoppingList::create($this->lists->nextIdentity(), $userId, $name);
        $this->lists->save($list);

        return $list;
    }

    /**
     * Build a list from a natural-language request via the AI assistant, e.g.
     * "ingredients for jollof rice for 6".
     */
    public function buildFromPrompt(string $userId, string $name, string $prompt): ShoppingList
    {
        $list = ShoppingList::create($this->lists->nextIdentity(), $userId, $name);
        foreach ($this->advisor->buildShoppingList($prompt, $userId) as $line) {
            $list->addLine($line);
        }
        $this->lists->save($list);

        return $list;
    }

    /** @return list<ShoppingList> */
    public function forUser(string $userId): array
    {
        return $this->lists->forUser($userId);
    }

    public function addLine(string $listId, string $userId, string $name, int $quantity, ?string $productId): ShoppingList
    {
        $list = $this->owned($listId, $userId);
        $list->addLine($name, $quantity, $productId);
        $this->lists->save($list);

        return $list;
    }

    public function toggleLine(string $listId, string $userId, int $index, bool $bought): ShoppingList
    {
        $list = $this->owned($listId, $userId);
        $list->toggleBought($index, $bought);
        $this->lists->save($list);

        return $list;
    }

    public function removeLine(string $listId, string $userId, int $index): ShoppingList
    {
        $list = $this->owned($listId, $userId);
        $list->removeLine($index);
        $this->lists->save($list);

        return $list;
    }

    public function delete(string $listId, string $userId): void
    {
        $this->owned($listId, $userId);
        $this->lists->delete($listId);
    }

    private function owned(string $listId, string $userId): ShoppingList
    {
        $list = $this->lists->findById($listId) ?? throw CommerceNotFound::of('shopping list', $listId);
        if ($list->userId() !== $userId) {
            throw new NotResourceOwner();
        }

        return $list;
    }
}
