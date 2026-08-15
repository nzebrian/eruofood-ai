<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Provider;

use EruoFood\Dispatch\Application\Port\CandidateSource;
use EruoFood\Dispatch\Application\Port\DeliveryLifecycle;
use EruoFood\Dispatch\Application\Port\RiderDirectory;
use EruoFood\Dispatch\Application\Port\RiderPerformanceQuery;
use EruoFood\Dispatch\Application\Port\RiderWorkloadQuery;
use EruoFood\Dispatch\Application\Port\ServiceAreaCheck;
use EruoFood\Dispatch\Application\Service\AssignmentService;
use EruoFood\Dispatch\Application\Service\CandidateDiscoveryService;
use EruoFood\Dispatch\Application\Service\DeliveryProgressService;
use EruoFood\Dispatch\Application\Service\DispatchEngine;
use EruoFood\Dispatch\Application\Service\DispatchOperationsService;
use EruoFood\Dispatch\Application\Service\EligibilityService;
use EruoFood\Dispatch\Application\Service\OfferExpiryService;
use EruoFood\Dispatch\Application\Service\ReassignmentService;
use EruoFood\Dispatch\Application\Service\ScoringService;
use EruoFood\Dispatch\Application\Service\VehicleService;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Eligibility\Rule\FairnessCapNotReached;
use EruoFood\Dispatch\Domain\Eligibility\Rule\HasDispatchableVehicle;
use EruoFood\Dispatch\Domain\Eligibility\Rule\HasNoConflictingDelivery;
use EruoFood\Dispatch\Domain\Eligibility\Rule\IsWithinServiceArea;
use EruoFood\Dispatch\Domain\Eligibility\Rule\LocationIsAccurate;
use EruoFood\Dispatch\Domain\Eligibility\Rule\LocationIsFresh;
use EruoFood\Dispatch\Domain\Eligibility\Rule\RiderIdentityIsVerified;
use EruoFood\Dispatch\Domain\Eligibility\Rule\RiderIsActive;
use EruoFood\Dispatch\Domain\Eligibility\Rule\RiderIsAvailable;
use EruoFood\Dispatch\Domain\Eligibility\Rule\VehicleDocumentsAreCurrent;
use EruoFood\Dispatch\Domain\Eligibility\Rule\VehicleIsSuitable;
use EruoFood\Dispatch\Domain\Event\DeliveryAssigned;
use EruoFood\Dispatch\Domain\Event\OfferExpired;
use EruoFood\Dispatch\Domain\Event\OfferMade;
use EruoFood\Dispatch\Domain\Event\ReassignmentRequired;
use EruoFood\Dispatch\Domain\Event\VehicleVerificationDecided;
use EruoFood\Dispatch\Domain\Offer\OfferRepository;
use EruoFood\Dispatch\Domain\Request\DispatchRequestRepository;
use EruoFood\Dispatch\Domain\Scoring\FairnessPolicy;
use EruoFood\Dispatch\Domain\Vehicle\VehicleRepository;
use EruoFood\Dispatch\Infrastructure\Directory\TableRiderDirectory;
use EruoFood\Dispatch\Infrastructure\Event\DispatchNotificationSubscriber;
use EruoFood\Dispatch\Infrastructure\Geo\GeoCandidateSource;
use EruoFood\Dispatch\Infrastructure\Geo\GeoServiceAreaCheck;
use EruoFood\Dispatch\Infrastructure\Marketplace\MarketplaceDeliveryLifecycle;
use EruoFood\Dispatch\Infrastructure\Performance\ReviewsRiderPerformanceQuery;
use EruoFood\Dispatch\Infrastructure\Persistence\AssignmentWorkloadQuery;
use EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\EloquentAssignmentRepository;
use EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\EloquentDispatchRequestRepository;
use EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\EloquentOfferRepository;
use EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\EloquentVehicleRepository;
use EruoFood\Dispatch\Interface\Console\ExpireOffersCommand;
use EruoFood\Dispatch\Interface\Console\VehicleBackfillReportCommand;
use EruoFood\Dispatch\Interface\Http\Controller\DispatchPresenter;
use EruoFood\Geo\Application\Service\RiderLocationService;
use EruoFood\Geo\Contracts\DeliveryDistanceProvider;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Verification\Contracts\VerificationStatusQuery;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Composition root for the Dispatch context.
 *
 * Every operational lever is read here from `config/dispatch.php` rather than
 * inside a service, so the services stay constructible in a test without a
 * config repository and an operator has one file to read.
 *
 * Nothing is resolved eagerly in {@see boot()}. M25 learned that lesson the
 * expensive way: eagerly constructing a subscriber there dragged an encrypter
 * into `package:discover`, which runs before an application key exists, and
 * took two CI jobs down at `composer install`.
 */
final class DispatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../../config/dispatch.php', 'dispatch');

        $this->app->bind(VehicleRepository::class, EloquentVehicleRepository::class);
        $this->app->bind(DispatchRequestRepository::class, EloquentDispatchRequestRepository::class);
        $this->app->bind(OfferRepository::class, EloquentOfferRepository::class);
        $this->app->bind(AssignmentRepository::class, EloquentAssignmentRepository::class);
        $this->app->bind(RiderDirectory::class, TableRiderDirectory::class);
        $this->app->bind(DeliveryLifecycle::class, MarketplaceDeliveryLifecycle::class);

        $this->app->singleton(VehicleService::class, fn ($app): VehicleService => new VehicleService(
            $app->make(VehicleRepository::class),
            $app->make(RiderDirectory::class),
            $app->make(TransactionManager::class),
            $app->make(EventBus::class),
            $app->make(Clock::class),
            (int) config('dispatch.vehicles.max_per_rider', 3),
            (int) config('dispatch.vehicles.expiry_warning_days', 14),
        ));

        $this->app->singleton(AssignmentService::class, fn ($app): AssignmentService => new AssignmentService(
            $app->make(OfferRepository::class),
            $app->make(AssignmentRepository::class),
            $app->make(DispatchRequestRepository::class),
            $app->make(RiderDirectory::class),
            $app->make(CandidateSource::class),
            $app->make(EligibilityService::class),
            $app->make(DeliveryLifecycle::class),
            $app->make(TransactionManager::class),
            $app->make(IdempotencyStore::class),
            $app->make(EventBus::class),
            $app->make(Clock::class),
        ));

        $this->app->singleton(OfferExpiryService::class, fn ($app): OfferExpiryService => new OfferExpiryService(
            $app->make(OfferRepository::class),
            $app->make(DispatchRequestRepository::class),
            $app->make(TransactionManager::class),
            $app->make(EventBus::class),
            $app->make(Clock::class),
        ));

        $this->app->singleton(ReassignmentService::class, fn ($app): ReassignmentService => new ReassignmentService(
            $app->make(AssignmentRepository::class),
            $app->make(DispatchRequestRepository::class),
            $app->make(DeliveryLifecycle::class),
            $app->make(TransactionManager::class),
            $app->make(EventBus::class),
            $app->make(Clock::class),
            // Below this, a fresh search would fail before it could plausibly
            // succeed — wasting the pool's attention and delaying the honest
            // answer that this delivery needs a human.
            (int) config('dispatch.reassignment.minimum_budget_seconds', 60),
        ));

        $this->app->singleton(
            DeliveryProgressService::class,
            fn ($app): DeliveryProgressService => new DeliveryProgressService(
                $app->make(AssignmentRepository::class),
                $app->make(DeliveryLifecycle::class),
                $app->make(RiderDirectory::class),
                $app->make(TransactionManager::class),
                $app->make(Clock::class),
            ),
        );

        $this->app->singleton(DispatchEngine::class, fn ($app): DispatchEngine => new DispatchEngine(
            $app->make(DispatchRequestRepository::class),
            $app->make(OfferRepository::class),
            $app->make(CandidateDiscoveryService::class),
            $app->make(ScoringService::class),
            $app->make(DeliveryLifecycle::class),
            $app->make(TransactionManager::class),
            $app->make(EventBus::class),
            $app->make(Clock::class),
            // OFF by default, exactly as M25's routed pricing was. Turning it
            // on changes how work reaches every rider; the manual vendor
            // assignment path keeps working either way.
            (bool) config('dispatch.engine.enabled', false),
            (int) config('dispatch.offer.ttl_seconds', 45),
            (int) config('dispatch.offer.concurrent_offers', 1),
            (int) config('dispatch.reassignment.max_attempts', 5),
            (int) config('dispatch.reassignment.max_duration_seconds', 600),
            (bool) config('dispatch.reassignment.exclude_decliners', true),
        ));

        $this->app->singleton(
            DispatchOperationsService::class,
            fn ($app): DispatchOperationsService => new DispatchOperationsService(
                $app->make(DispatchRequestRepository::class),
                $app->make(AssignmentRepository::class),
                $app->make(OfferRepository::class),
                $app->make(VehicleRepository::class),
                $app->make(RiderDirectory::class),
                $app->make(RiderLocationService::class),
                $app->make(DeliveryLifecycle::class),
                $app->make(ReassignmentService::class),
                $app->make(TransactionManager::class),
                $app->make(Clock::class),
                // Break-glass, retained even when the engine is on, and audited
                // every time it is used.
                (bool) config('dispatch.engine.allow_manual_override', true),
            ),
        );

        // The one place that decides how much of a dispatch record leaves the
        // building. Per-controller shaping is how a private field ends up on a
        // public endpoint one refactor later.
        $this->app->singleton(DispatchPresenter::class);

        $this->app->bind(RiderWorkloadQuery::class, AssignmentWorkloadQuery::class);
        $this->app->bind(ServiceAreaCheck::class, GeoServiceAreaCheck::class);
        $this->app->bind(RiderPerformanceQuery::class, ReviewsRiderPerformanceQuery::class);

        $this->app->singleton(CandidateSource::class, fn ($app): GeoCandidateSource => new GeoCandidateSource(
            $app->make(RiderLocationService::class),
            $app->make(RiderDirectory::class),
            $app->make(VehicleRepository::class),
            $app->make(RiderWorkloadQuery::class),
            (int) config('dispatch.fairness.recent_window_seconds', 3_600),
        ));

        /*
        | The eligibility chain, in the order the rejection breakdown reads best.
        |
        | Order matters for reporting rather than for correctness — a rider is
        | counted once, under their *first* objection — so the cheapest and most
        | explanatory checks come first. An operator seeing "nine suspended" is
        | better served than one seeing "nine failed some rule".
        |
        | The three mandatory rules are in this list like any other, and
        | `EligibilityService` keeps them in force whatever configuration says.
        */
        $this->app->singleton(EligibilityService::class, fn ($app): EligibilityService => new EligibilityService(
            [
                new RiderIsActive(),
                new RiderIsAvailable(),
                new RiderIdentityIsVerified($app->make(VerificationStatusQuery::class)),
                new HasDispatchableVehicle(),
                new VehicleDocumentsAreCurrent(),
                new VehicleIsSuitable(),
                new LocationIsFresh((int) config('geo.privacy.rider_location_stale_seconds', 300)),
                new LocationIsAccurate((float) config('dispatch.eligibility.max_accuracy_metres', 250.0)),
                new HasNoConflictingDelivery(),
                new IsWithinServiceArea($app->make(ServiceAreaCheck::class)),
                new FairnessCapNotReached(
                    (bool) config('dispatch.fairness.enabled', true),
                    (int) config('dispatch.fairness.consecutive_assignment_cap', 5),
                ),
            ],
            (array) config('dispatch.eligibility.optional_rules', []),
        ));

        $this->app->singleton(ScoringService::class, fn ($app): ScoringService => new ScoringService(
            $app->make(RiderPerformanceQuery::class),
            $app->make(FairnessPolicy::class),
            // M25's provider, consumed through its published contract. Dispatch
            // never measures a distance itself and never touches what a
            // customer is charged.
            $app->make(DeliveryDistanceProvider::class),
            (array) config('dispatch.scoring.weights', []),
            (int) config('dispatch.scoring.normalisation.max_distance_metres', 15_000),
            (int) config('dispatch.scoring.normalisation.max_eta_seconds', 3_600),
            (int) config('dispatch.scoring.normalisation.max_active_deliveries', 3),
        ));

        $this->app->singleton(
            CandidateDiscoveryService::class,
            fn ($app): CandidateDiscoveryService => new CandidateDiscoveryService(
                $app->make(CandidateSource::class),
                $app->make(EligibilityService::class),
                (float) config('dispatch.discovery.initial_radius_metres', 3_000),
                (float) config('dispatch.discovery.max_radius_metres', 15_000),
                (float) config('dispatch.discovery.radius_expansion_factor', 2.0),
                (int) config('dispatch.discovery.min_pool_size', 3),
                (int) config('dispatch.discovery.max_pool_size', 25),
                (int) config('dispatch.discovery.max_raw_candidates', 100),
            ),
        );

        /*
        | Fairness, clamped against the proximity weight.
        |
        | Built through the bounded factory rather than the constructor, which
        | is what makes "fairness cannot send a delivery across the city" true
        | by construction. An earlier draft used the constructor and a rider
        | twelve kilometres away beat one five hundred metres away.
        */
        $this->app->singleton(FairnessPolicy::class, fn (): FairnessPolicy => FairnessPolicy::boundedBy(
            (float) config('dispatch.scoring.weights.proximity', 0.30),
            (bool) config('dispatch.fairness.enabled', true),
            (float) config('dispatch.fairness.max_penalty', 0.20),
            (float) config('dispatch.fairness.penalty_per_recent_assignment', 0.08),
            (int) config('dispatch.fairness.idle_boost_after_seconds', 1_800),
            (float) config('dispatch.fairness.idle_boost', 0.10),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        /*
        | Rider notifications, through M24's service.
        |
        | Registered by class name so the container builds the subscriber only
        | when an event fires. Resolving it here would drag the notification
        | stack into every process start — including `package:discover`, which
        | is exactly how M25 took two CI jobs down at `composer install`.
        */
        $dispatcher = $this->app->make(Dispatcher::class);

        $listen = function (string $event, callable $call) use ($dispatcher): void {
            $dispatcher->listen($event, function (object $payload) use ($call): void {
                /*
                | The catch is *here*, around construction as well as handling.
                |
                | The subscriber guards its own `notify()` call, but the
                | container resolves the notification stack when it builds the
                | subscriber — before any of that guarding runs. A broken
                | notifier would therefore have failed the rider's acceptance
                | from inside the constructor, which is precisely the outcome
                | the guarding exists to prevent.
                */
                try {
                    $call($payload);
                } catch (Throwable) {
                    // A notification is a side effect of a decision already
                    // committed. A missed push is bad; rolling back a rider's
                    // acceptance because a push failed is worse.
                }
            });
        };

        $subscriber = fn (): DispatchNotificationSubscriber => $this->app->make(DispatchNotificationSubscriber::class);

        $listen('dispatch.offer_made', function (object $e) use ($subscriber): void {
            if ($e instanceof OfferMade) {
                $subscriber()->onOfferMade($e);
            }
        });

        $listen('dispatch.delivery_assigned', function (object $e) use ($subscriber): void {
            if ($e instanceof DeliveryAssigned) {
                $subscriber()->onDeliveryAssigned($e);
            }
        });

        $listen('dispatch.offer_expired', function (object $e) use ($subscriber): void {
            if ($e instanceof OfferExpired) {
                $subscriber()->onOfferExpired($e);
            }
        });

        $listen('dispatch.reassignment_required', function (object $e) use ($subscriber): void {
            if ($e instanceof ReassignmentRequired) {
                $subscriber()->onReassignmentRequired($e);
            }
        });

        $listen('dispatch.vehicle_verification_decided', function (object $e) use ($subscriber): void {
            if ($e instanceof VehicleVerificationDecided) {
                $subscriber()->onVehicleVerificationDecided($e);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                VehicleBackfillReportCommand::class,
                ExpireOffersCommand::class,
            ]);
        }
    }
}
