<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Seeder;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Reward\RewardRepository;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\RewardModel;
use Illuminate\Database\Seeder;

/**
 * Seeds a starter rewards catalogue so the storefront is usable out of the box.
 * Idempotent — skips when any reward already exists.
 */
final class LoyaltySeeder extends Seeder
{
    public function run(): void
    {
        if (RewardModel::query()->exists()) {
            return;
        }

        /** @var RewardRepository $rewards */
        $rewards = app(RewardRepository::class);

        $catalogue = [
            ['name' => '₦500 off your next order', 'description' => 'A ₦500 discount voucher.', 'benefit_type' => 'discount', 'benefit_value' => 50000, 'points_cost' => 500, 'stock' => null],
            ['name' => 'Free delivery', 'description' => 'One free delivery on your next order.', 'benefit_type' => 'free_delivery', 'benefit_value' => 0, 'points_cost' => 300, 'stock' => null],
            ['name' => '₦2,000 off', 'description' => 'A ₦2,000 discount voucher.', 'benefit_type' => 'discount', 'benefit_value' => 200000, 'points_cost' => 1800, 'stock' => 100],
        ];

        foreach ($catalogue as $item) {
            $rewards->save(\EruoFood\Loyalty\Domain\Reward\Reward::create(
                $rewards->nextIdentity(),
                (string) $item['name'],
                (string) $item['description'],
                (string) $item['benefit_type'],
                (int) $item['benefit_value'],
                (int) $item['points_cost'],
                $item['stock'],
                new DateTimeImmutable(),
            ));
        }
    }
}
