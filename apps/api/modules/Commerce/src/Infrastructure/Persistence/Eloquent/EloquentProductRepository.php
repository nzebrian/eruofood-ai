<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Domain\Catalog\ProductRepository;
use EruoFood\Commerce\Domain\Catalog\ProductSearchCriteria;
use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;
use EruoFood\Commerce\Domain\Enum\ProductStatus;
use EruoFood\Commerce\Domain\ValueObject\Barcode;
use EruoFood\Commerce\Domain\ValueObject\ProductVariant;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\ProductModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class EloquentProductRepository implements ProductRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Product
    {
        $m = ProductModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findBySlug(string $slug): ?Product
    {
        $m = ProductModel::query()->where('slug', $slug)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function slugExists(string $slug): bool
    {
        return ProductModel::query()->where('slug', $slug)->exists();
    }

    public function findByBarcode(string $barcode): ?Product
    {
        $m = ProductModel::query()->where('barcode', $barcode)->where('status', ProductStatus::Published->value)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function search(ProductSearchCriteria $criteria, int $page, int $perPage): Paginated
    {
        $query = ProductModel::query()->where('status', ProductStatus::Published->value);
        $this->applyFilters($query, $criteria);
        $this->applySort($query, $criteria->sort);

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (ProductModel $m): Product => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function forStore(string $storeId, int $page, int $perPage): Paginated
    {
        $paginator = ProductModel::query()
            ->where('store_id', $storeId)
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (ProductModel $m): Product => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function withStatus(ProductStatus $status, int $page, int $perPage): Paginated
    {
        $paginator = ProductModel::query()
            ->where('status', $status->value)
            ->orderBy('created_at')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (ProductModel $m): Product => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function related(Product $product, int $limit): array
    {
        $query = ProductModel::query()
            ->where('status', ProductStatus::Published->value)
            ->where('id', '!=', $product->id());

        if ($product->categoryId() !== null) {
            $query->where('category_id', $product->categoryId());
        } elseif ($product->department() !== null) {
            $query->where('department', $product->department()->value);
        } else {
            $query->where('kind', $product->kind()->value);
        }

        return array_map(
            fn (ProductModel $m): Product => $this->toDomain($m),
            $query->orderByDesc('rating_average')->limit($limit)->get()->all(),
        );
    }

    public function findManyById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $models = ProductModel::query()->whereIn('id', $ids)->get()->keyBy('id');

        $ordered = [];
        foreach ($ids as $id) {
            $m = $models->get($id);
            if ($m instanceof ProductModel) {
                $ordered[] = $this->toDomain($m);
            }
        }

        return $ordered;
    }

    public function save(Product $product): void
    {
        $model = ProductModel::query()->find($product->id()) ?? new ProductModel();
        $model->id = $product->id();
        $model->store_id = $product->storeId();
        $model->category_id = $product->categoryId();
        $model->name = $product->name();
        $model->slug = (string) $product->slug();
        $model->kind = $product->kind()->value;
        $model->department = $product->department()?->value;
        $model->description = $product->description();
        $model->description_ai_generated = $product->descriptionIsAiGenerated();
        $model->base_price_minor = $product->basePrice()->minorUnits;
        $model->variants = array_map(static fn (ProductVariant $v): array => $v->toArray(), $product->variants());
        $model->images = $product->images();
        $model->tags = $product->tags();
        $model->status = $product->status()->value;
        $model->featured = $product->isFeatured();
        $model->barcode = $product->barcode() !== null ? (string) $product->barcode() : null;
        $model->brand = $product->brand();
        $model->rating_average = $product->ratingAverage();
        $model->rating_count = $product->ratingCount();
        $model->save();
    }

    public function delete(string $id): void
    {
        ProductModel::query()->where('id', $id)->delete();
    }

    /** @param Builder<ProductModel> $query */
    private function applyFilters(Builder $query, ProductSearchCriteria $criteria): void
    {
        if ($criteria->term !== null && $criteria->term !== '') {
            $term = '%'.mb_strtolower($criteria->term).'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(brand, \'\')) LIKE ?', [$term]);
            });
        }
        if ($criteria->storeId !== null) {
            $query->where('store_id', $criteria->storeId);
        }
        if ($criteria->categoryId !== null) {
            $query->where('category_id', $criteria->categoryId);
        }
        if ($criteria->kind !== null) {
            $query->where('kind', $criteria->kind->value);
        }
        if ($criteria->department !== null) {
            $query->where('department', $criteria->department->value);
        }
        if ($criteria->minPriceMinor !== null) {
            $query->where('base_price_minor', '>=', $criteria->minPriceMinor);
        }
        if ($criteria->maxPriceMinor !== null) {
            $query->where('base_price_minor', '<=', $criteria->maxPriceMinor);
        }
        if ($criteria->featuredOnly) {
            $query->where('featured', true);
        }
    }

    /** @param Builder<ProductModel> $query */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('base_price_minor'),
            'price_desc' => $query->orderByDesc('base_price_minor'),
            'rating' => $query->orderByDesc('rating_average'),
            'newest' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('featured')->orderByDesc('rating_average'),
        };
    }

    private function toDomain(ProductModel $m): Product
    {
        return Product::reconstitute(
            id: $m->id,
            storeId: $m->store_id,
            categoryId: $m->category_id,
            name: $m->name,
            slug: new Slug($m->slug),
            kind: ProductKind::from($m->kind),
            department: $m->department !== null ? GroceryDepartment::from($m->department) : null,
            description: $m->description,
            descriptionIsAiGenerated: $m->description_ai_generated,
            basePrice: new Money($m->base_price_minor, $this->currency),
            variants: array_map(fn (array $v): ProductVariant => ProductVariant::fromArray($v, $this->currency), $m->variants ?? []),
            images: $m->images ?? [],
            tags: $m->tags ?? [],
            status: ProductStatus::from($m->status),
            featured: $m->featured,
            barcode: $m->barcode !== null ? new Barcode($m->barcode) : null,
            brand: $m->brand,
            ratingAverage: $m->rating_average,
            ratingCount: $m->rating_count,
        );
    }
}
