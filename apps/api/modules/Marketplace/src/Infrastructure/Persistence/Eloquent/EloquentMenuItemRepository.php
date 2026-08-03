<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent;

use EruoFood\Marketplace\Domain\Menu\MenuItem;
use EruoFood\Marketplace\Domain\Menu\MenuItemRepository;
use EruoFood\Marketplace\Domain\ValueObject\MenuVariant;
use EruoFood\Marketplace\Domain\ValueObject\Promotion;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\MenuItemModel;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\VendorModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Str;

final class EloquentMenuItemRepository implements MenuItemRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?MenuItem
    {
        $m = MenuItemModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findMany(array $ids): array
    {
        $out = [];
        foreach (MenuItemModel::query()->whereIn('id', $ids)->get() as $m) {
            $out[$m->id] = $this->toDomain($m);
        }

        return $out;
    }

    public function forVendor(string $vendorId, bool $onlyAvailable = false): array
    {
        $query = MenuItemModel::query()->where('vendor_id', $vendorId);
        if ($onlyAvailable) {
            $query->where('available', true);
        }

        return array_values(array_map(fn (MenuItemModel $m): MenuItem => $this->toDomain($m), $query->orderBy('name')->get()->all()));
    }

    public function search(?string $term, ?string $vendorId, bool $featuredOnly, int $page, int $perPage): Paginated
    {
        $query = MenuItemModel::query()
            ->where('available', true)
            ->whereIn('vendor_id', VendorModel::query()->where('status', 'verified')->select('id'));

        if ($term !== null && $term !== '') {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']);
        }
        if ($vendorId !== null && $vendorId !== '') {
            $query->where('vendor_id', $vendorId);
        }
        if ($featuredOnly) {
            $query->where('featured', true);
        }

        $paginator = $query->orderByDesc('featured')->orderBy('name')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (MenuItemModel $m): MenuItem => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(MenuItem $item): void
    {
        $model = MenuItemModel::query()->find($item->id()) ?? new MenuItemModel();
        $model->id = $item->id();
        $model->vendor_id = $item->vendorId();
        $model->category_id = $item->categoryId();
        $model->name = $item->name();
        $model->description = $item->description();
        $model->description_ai_generated = $item->descriptionIsAiGenerated();
        $model->base_price_minor = $item->basePrice()->minorUnits;
        $model->currency = $item->basePrice()->currency;
        $model->variants = array_map(static fn (MenuVariant $v): array => $v->toArray(), $item->variants());
        $model->available = $item->isAvailable();
        $model->images = $item->images();
        $model->tags = $item->tags();
        $model->featured = $item->isFeatured();
        $model->promotion = $item->promotion()?->toArray();
        $model->track_inventory = $item->tracksInventory();
        $model->stock = $item->stock();
        $model->calories = $item->calories();
        $model->nutrition_item_id = $item->nutritionItemId();
        $model->save();
    }

    public function delete(string $id): void
    {
        MenuItemModel::query()->where('id', $id)->delete();
    }

    private function toDomain(MenuItemModel $m): MenuItem
    {
        $currency = $m->currency;

        return MenuItem::reconstitute(
            id: $m->id,
            vendorId: $m->vendor_id,
            categoryId: $m->category_id,
            name: $m->name,
            description: $m->description,
            descriptionIsAiGenerated: $m->description_ai_generated,
            basePrice: new Money($m->base_price_minor, $currency),
            variants: array_map(static fn (array $v): MenuVariant => MenuVariant::fromArray($v, $currency), $m->variants ?? []),
            available: $m->available,
            images: $m->images ?? [],
            tags: $m->tags ?? [],
            featured: $m->featured,
            promotion: $m->promotion !== null ? Promotion::fromArray($m->promotion) : null,
            trackInventory: $m->track_inventory,
            stock: $m->stock,
            calories: $m->calories,
            nutritionItemId: $m->nutrition_item_id,
        );
    }
}
