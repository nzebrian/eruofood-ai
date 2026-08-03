<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Reviews\Domain\Enum\ReviewStatus;
use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\Review\Review;
use EruoFood\Reviews\Domain\Review\ReviewQuery;
use EruoFood\Reviews\Domain\Review\ReviewRepository;
use EruoFood\Reviews\Domain\ValueObject\OwnerResponse;
use EruoFood\Reviews\Domain\ValueObject\Rating;
use EruoFood\Reviews\Domain\ValueObject\Subject;
use EruoFood\Reviews\Infrastructure\Persistence\Eloquent\Model\ReviewModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class EloquentReviewRepository implements ReviewRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Review
    {
        $m = ReviewModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findBySubjectAndAuthor(Subject $subject, string $authorId): ?Review
    {
        $m = ReviewModel::query()
            ->where('subject_type', $subject->type->value)
            ->where('subject_id', $subject->id)
            ->where('author_id', $authorId)
            ->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function search(ReviewQuery $query): Paginated
    {
        $builder = ReviewModel::query();
        if ($query->subject !== null) {
            $builder->where('subject_type', $query->subject->type->value)
                ->where('subject_id', $query->subject->id);
        }
        if ($query->authorId !== null) {
            $builder->where('author_id', $query->authorId);
        }
        if ($query->status !== null) {
            $builder->where('status', $query->status->value);
        }
        if ($query->verifiedOnly) {
            $builder->where('verified_purchase', true);
        }
        if ($query->minRating !== null) {
            $builder->where('rating', '>=', $query->minRating);
        }

        $this->applySort($builder, $query->sort);

        $paginator = $builder->paginate(perPage: $query->perPage, page: $query->page);

        return new Paginated(
            array_values(array_map(fn (ReviewModel $m): Review => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $query->page,
            $query->perPage,
        );
    }

    public function publishedForSubject(Subject $subject): array
    {
        return array_values(array_map(
            fn (ReviewModel $m): Review => $this->toDomain($m),
            ReviewModel::query()
                ->where('subject_type', $subject->type->value)
                ->where('subject_id', $subject->id)
                ->where('status', ReviewStatus::Published->value)
                ->get()
                ->all(),
        ));
    }

    public function save(Review $review): void
    {
        $model = ReviewModel::query()->find($review->id()) ?? new ReviewModel();
        $model->id = $review->id();
        $model->subject_type = $review->subject()->type->value;
        $model->subject_id = $review->subject()->id;
        $model->author_id = $review->authorId();
        $model->rating = $review->rating()->value;
        $model->title = $review->title();
        $model->body = $review->body();
        $model->photos = $review->photos();
        $model->verified_purchase = $review->isVerifiedPurchase();
        $model->status = $review->status()->value;
        $model->helpful_yes = $review->helpfulYes();
        $model->helpful_no = $review->helpfulNo();
        $model->owner_response = $review->ownerResponse()?->toArray();
        $model->moderated_by = $review->moderatedBy();
        $model->moderation_reason = $review->moderationReason();
        $model->created_at = $review->createdAt();
        $model->updated_at = $review->updatedAt();
        $model->save();
    }

    private function applySort(Builder $builder, string $sort): void
    {
        match ($sort) {
            'oldest' => $builder->orderBy('created_at'),
            'helpful' => $builder->orderByDesc('helpful_yes')->orderByDesc('created_at'),
            'rating_desc' => $builder->orderByDesc('rating')->orderByDesc('created_at'),
            'rating_asc' => $builder->orderBy('rating')->orderByDesc('created_at'),
            default => $builder->orderByDesc('created_at'),
        };
    }

    private function toDomain(ReviewModel $m): Review
    {
        /** @var array<string, mixed>|null $ownerResponse */
        $ownerResponse = $m->owner_response;

        return Review::reconstitute(
            id: $m->id,
            subject: new Subject(SubjectType::from($m->subject_type), $m->subject_id),
            authorId: $m->author_id,
            rating: new Rating((int) $m->rating),
            title: $m->title,
            body: $m->body,
            photos: array_values(array_map('strval', $m->photos ?? [])),
            verifiedPurchase: (bool) $m->verified_purchase,
            status: ReviewStatus::from($m->status),
            helpfulYes: (int) $m->helpful_yes,
            helpfulNo: (int) $m->helpful_no,
            ownerResponse: is_array($ownerResponse) && $ownerResponse !== [] ? OwnerResponse::fromArray($ownerResponse) : null,
            moderatedBy: $m->moderated_by,
            moderationReason: $m->moderation_reason,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
