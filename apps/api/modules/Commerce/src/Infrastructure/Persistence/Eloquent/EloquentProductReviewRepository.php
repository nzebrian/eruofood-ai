<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Catalog\ProductReview;
use EruoFood\Commerce\Domain\Catalog\ProductReviewRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\ProductReviewModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentProductReviewRepository implements ProductReviewRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function existsFor(string $productId, string $userId): bool
    {
        return ProductReviewModel::query()
            ->where('product_id', $productId)
            ->where('user_id', $userId)
            ->exists();
    }

    public function forProduct(string $productId, int $page, int $perPage): Paginated
    {
        $paginator = ProductReviewModel::query()
            ->where('product_id', $productId)
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (ProductReviewModel $m): ProductReview => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function summaryFor(string $productId): array
    {
        $query = ProductReviewModel::query()->where('product_id', $productId);
        $count = (int) $query->count();
        $average = $count > 0 ? (float) $query->avg('rating') : 0.0;

        return ['average' => $average, 'count' => $count];
    }

    public function save(ProductReview $review): void
    {
        $model = ProductReviewModel::query()->find($review->id()) ?? new ProductReviewModel();
        $model->id = $review->id();
        $model->product_id = $review->productId();
        $model->user_id = $review->userId();
        $model->rating = $review->rating();
        $model->comment = $review->comment();
        $model->created_at = $review->createdAt();
        $model->save();
    }

    private function toDomain(ProductReviewModel $m): ProductReview
    {
        return ProductReview::reconstitute(
            id: $m->id,
            productId: $m->product_id,
            userId: $m->user_id,
            rating: $m->rating,
            comment: $m->comment,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
