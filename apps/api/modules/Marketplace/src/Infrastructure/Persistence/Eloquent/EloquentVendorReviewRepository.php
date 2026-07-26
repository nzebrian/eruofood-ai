<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Marketplace\Domain\Vendor\VendorReview;
use EruoFood\Marketplace\Domain\Vendor\VendorReviewRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\VendorReviewModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentVendorReviewRepository implements VendorReviewRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findByVendorAndUser(string $vendorId, string $userId): ?VendorReview
    {
        $m = VendorReviewModel::query()->where('vendor_id', $vendorId)->where('user_id', $userId)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forVendor(string $vendorId, int $page, int $perPage): Paginated
    {
        $paginator = VendorReviewModel::query()
            ->where('vendor_id', $vendorId)
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (VendorReviewModel $m): VendorReview => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(VendorReview $review): void
    {
        $model = VendorReviewModel::query()->find($review->id()) ?? new VendorReviewModel();
        $model->id = $review->id();
        $model->vendor_id = $review->vendorId();
        $model->user_id = $review->userId();
        $model->rating = $review->rating();
        $model->comment = $review->comment();
        $model->save();
    }

    public function summaryForVendor(string $vendorId): array
    {
        $row = VendorReviewModel::query()
            ->where('vendor_id', $vendorId)
            ->selectRaw('AVG(rating) as average, COUNT(*) as count')
            ->first();

        return ['average' => (float) ($row->average ?? 0), 'count' => (int) ($row->count ?? 0)];
    }

    private function toDomain(VendorReviewModel $m): VendorReview
    {
        return VendorReview::create(
            $m->id,
            $m->vendor_id,
            $m->user_id,
            $m->rating,
            $m->comment,
            DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
