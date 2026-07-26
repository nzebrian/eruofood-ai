<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Diary;

/** Persistence port for the daily nutrition diary (Repository Pattern). */
interface DiaryRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?DiaryEntry;

    /**
     * All of a user's entries for one calendar day (Y-m-d), meal order.
     *
     * @return list<DiaryEntry>
     */
    public function forUserAndDate(string $userId, string $date): array;

    public function save(DiaryEntry $entry): void;

    public function delete(string $id): void;
}
