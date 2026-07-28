<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Review;

use EruoFood\Reviews\Domain\ValueObject\Subject;
use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Review} aggregate. */
interface ReviewRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Review;

    /** One user may review a subject once — used to reject duplicates. */
    public function findBySubjectAndAuthor(Subject $subject, string $authorId): ?Review;

    /**
     * @return Paginated<Review>
     */
    public function search(ReviewQuery $query): Paginated;

    /**
     * Every review currently counting toward a subject's rating (published only)
     * — the input to the summary projection.
     *
     * @return list<Review>
     */
    public function publishedForSubject(Subject $subject): array;

    public function save(Review $review): void;
}
