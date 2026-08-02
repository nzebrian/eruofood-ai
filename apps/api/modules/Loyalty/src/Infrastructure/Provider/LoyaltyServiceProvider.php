<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Provider;

use EruoFood\Loyalty\Application\Service\EventTranslator;
use EruoFood\Loyalty\Application\Service\LoyaltyService;
use EruoFood\Loyalty\Application\Service\ReferralService;
use EruoFood\Loyalty\Application\Service\TierProjector;
use EruoFood\Loyalty\Domain\Account\LoyaltyAccountRepository;
use EruoFood\Loyalty\Domain\Account\LoyaltyStatsRepository;
use EruoFood\Loyalty\Domain\Account\Tier;
use EruoFood\Loyalty\Domain\Account\TierPolicy;
use EruoFood\Loyalty\Domain\Referral\ReferralRepository;
use EruoFood\Loyalty\Domain\Reward\RedemptionRepository;
use EruoFood\Loyalty\Domain\Reward\RewardRepository;
use EruoFood\Loyalty\Infrastructure\Console\ScanExpiryCommand;
use EruoFood\Loyalty\Infrastructure\Event\DomainEventSubscriber;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\EloquentLoyaltyAccountRepository;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\EloquentLoyaltyStatsRepository;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\EloquentReferralRepository;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\EloquentRedemptionRepository;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\EloquentRewardRepository;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the Loyalty, Rewards & Referrals context. Builds the tier
 * ladder from config; binds the account/ledger, reward, redemption, referral and
 * stats repositories; wires the loyalty/redemption/referral services and the tier
 * projector (the single writer of tier); and subscribes the programme to
 * published order/review events — the only inbound coupling, one-way and by name.
 * No business module awards or stores its own points; ratings-style projections
 * (tier, redemptions) flow out via published events.
 */
final class LoyaltyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tier ladder (config-driven, shared by the projector, service and presenter).
        $this->app->singleton(TierPolicy::class, function ($app): TierPolicy {
            /** @var list<array<string, mixed>> $tiers */
            $tiers = (array) $app['config']->get('loyalty.tiers', []);

            return new TierPolicy(array_map(static fn (array $t): Tier => Tier::fromArray($t), array_values($tiers)));
        });

        // Repositories → Eloquent adapters.
        $this->app->bind(LoyaltyAccountRepository::class, EloquentLoyaltyAccountRepository::class);
        $this->app->bind(RewardRepository::class, EloquentRewardRepository::class);
        $this->app->bind(LoyaltyStatsRepository::class, EloquentLoyaltyStatsRepository::class);
        $this->app->bind(ReferralRepository::class, EloquentReferralRepository::class);
        $this->app->singleton(RedemptionRepository::class, fn ($app): RedemptionRepository => new EloquentRedemptionRepository(
            (string) $app['config']->get('loyalty.redemption_prefix', 'EFR'),
        ));

        // Tier projector — the single writer of a member's tier.
        $this->app->singleton(TierProjector::class, fn ($app): TierProjector => new TierProjector(
            $app->make(LoyaltyAccountRepository::class),
            $app->make(TierPolicy::class),
            $app->make(EventBus::class),
        ));

        // The one entry point for a member's points balance.
        $this->app->singleton(LoyaltyService::class, fn ($app): LoyaltyService => new LoyaltyService(
            $app->make(LoyaltyAccountRepository::class),
            $app->make(TierPolicy::class),
            $app->make(TierProjector::class),
            $app->make(EventBus::class),
            (int) $app['config']->get('loyalty.points_expiry_days', 365),
        ));

        // Referral programme.
        $this->app->singleton(ReferralService::class, fn ($app): ReferralService => new ReferralService(
            $app->make(ReferralRepository::class),
            $app->make(LoyaltyService::class),
            $app->make(EventBus::class),
            (int) $app['config']->get('loyalty.referral.referrer_points', 500),
            (int) $app['config']->get('loyalty.referral.referee_points', 250),
        ));

        // Event → points/referral translator.
        $this->app->bind(EventTranslator::class, function ($app): EventTranslator {
            /** @var array<string, array<string, mixed>> $earnRules */
            $earnRules = (array) $app['config']->get('loyalty.earn_rules', []);
            $referral = (array) $app['config']->get('loyalty.referral', []);

            return new EventTranslator(
                $app->make(LoyaltyService::class),
                $app->make(ReferralService::class),
                $this->normaliseEarnRules($earnRules),
                [
                    'event' => (string) ($referral['qualifying_event'] ?? ''),
                    'user_field' => (string) ($referral['qualifying_user_field'] ?? 'customerUserId'),
                ],
            );
        });

        $this->commands([ScanExpiryCommand::class]);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        // Subscribe to published order/review events (the only inbound coupling —
        // one-way, by event name) plus the referral qualifying event.
        /** @var array<string, mixed> $earnRules */
        $earnRules = (array) $this->app['config']->get('loyalty.earn_rules', []);
        /** @var array<string, mixed> $referral */
        $referral = (array) $this->app['config']->get('loyalty.referral', []);
        $events = array_keys($earnRules);
        $qualifying = (string) ($referral['qualifying_event'] ?? '');
        if ($qualifying !== '') {
            $events[] = $qualifying;
        }

        (new DomainEventSubscriber($this->app->make(Dispatcher::class), array_values($events)))->register();
    }

    /**
     * @param array<string, array<string, mixed>> $rules
     *
     * @return array<string, array{reason: string, user_field: string, amount_field: string|null, per_minor: float, points: int}>
     */
    private function normaliseEarnRules(array $rules): array
    {
        $out = [];
        foreach ($rules as $event => $rule) {
            $out[$event] = [
                'reason' => (string) ($rule['reason'] ?? 'earn'),
                'user_field' => (string) ($rule['user_field'] ?? 'userId'),
                'amount_field' => isset($rule['amount_field']) && $rule['amount_field'] !== null ? (string) $rule['amount_field'] : null,
                'per_minor' => (float) ($rule['per_minor'] ?? 0.0),
                'points' => (int) ($rule['points'] ?? 0),
            ];
        }

        return $out;
    }
}
