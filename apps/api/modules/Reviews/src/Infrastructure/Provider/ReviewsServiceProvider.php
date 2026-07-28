<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Provider;

use EruoFood\Ai\Contracts\AiAdvisor;
use EruoFood\Reviews\Application\Port\ContentModerator;
use EruoFood\Reviews\Application\Service\EventTranslator;
use EruoFood\Reviews\Application\Service\ModerationService;
use EruoFood\Reviews\Application\Service\RatingProjector;
use EruoFood\Reviews\Application\Service\ReviewAnalyticsService;
use EruoFood\Reviews\Application\Service\ReviewService;
use EruoFood\Reviews\Domain\Eligibility\PurchaseEligibilityRepository;
use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\Rating\RatingSummaryRepository;
use EruoFood\Reviews\Domain\Review\ReviewRepository;
use EruoFood\Reviews\Domain\Review\ReviewStatsRepository;
use EruoFood\Reviews\Infrastructure\Event\DomainEventSubscriber;
use EruoFood\Reviews\Infrastructure\Moderation\AiBackedContentModerator;
use EruoFood\Reviews\Infrastructure\Moderation\WordlistContentModerator;
use EruoFood\Reviews\Infrastructure\Persistence\Eloquent\EloquentPurchaseEligibilityRepository;
use EruoFood\Reviews\Infrastructure\Persistence\Eloquent\EloquentRatingSummaryRepository;
use EruoFood\Reviews\Infrastructure\Persistence\Eloquent\EloquentReviewRepository;
use EruoFood\Reviews\Infrastructure\Persistence\Eloquent\EloquentReviewStatsRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the Reviews & Ratings context. Binds the review, rating
 * summary, verified-purchase and stats repositories; the content moderator
 * (AI-backed when enabled, else the offline word-list); the rating projector
 * (the single writer of a subject's rating summary); and the review/moderation/
 * analytics services. It subscribes the verified-purchase ledger to published
 * order events — the only inbound coupling, one-way and by name. No business
 * module stores or aggregates its own reviews; ratings flow out via the
 * published rating-summary event.
 */
final class ReviewsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Repositories → Eloquent adapters.
        $this->app->bind(ReviewRepository::class, EloquentReviewRepository::class);
        $this->app->bind(RatingSummaryRepository::class, EloquentRatingSummaryRepository::class);
        $this->app->bind(PurchaseEligibilityRepository::class, EloquentPurchaseEligibilityRepository::class);
        $this->app->bind(ReviewStatsRepository::class, EloquentReviewStatsRepository::class);

        // Content moderator — AI-backed when enabled and available, else the
        // offline word-list. Both share the word-list as the certain first pass.
        $this->app->singleton(ContentModerator::class, function ($app): ContentModerator {
            /** @var list<string> $blocklist */
            $blocklist = (array) $app['config']->get('reviews.blocklist', []);
            $wordlist = new WordlistContentModerator(array_values(array_map('strval', $blocklist)));
            if ((bool) $app['config']->get('reviews.ai_moderation', false) && $app->bound(AiAdvisor::class)) {
                return new AiBackedContentModerator($app->make(AiAdvisor::class), $wordlist);
            }

            return $wordlist;
        });

        // The rating projector — the single writer of the rating summary and the
        // only place ratings are computed.
        $this->app->singleton(RatingProjector::class, fn ($app): RatingProjector => new RatingProjector(
            $app->make(ReviewRepository::class),
            $app->make(RatingSummaryRepository::class),
            $app->make(\EruoFood\Shared\Domain\EventBus::class),
        ));

        // The one entry point for every review interaction.
        $this->app->singleton(ReviewService::class, fn ($app): ReviewService => new ReviewService(
            $app->make(ReviewRepository::class),
            $app->make(RatingSummaryRepository::class),
            $app->make(PurchaseEligibilityRepository::class),
            $app->make(ContentModerator::class),
            $app->make(RatingProjector::class),
            $app->make(\EruoFood\Shared\Domain\EventBus::class),
            (string) $app['config']->get('reviews.moderation', 'post') === 'pre' ? 'pre' : 'post',
            (bool) $app['config']->get('reviews.content_filter', true),
            (int) $app['config']->get('reviews.max_photos', 6),
        ));

        $this->app->singleton(ModerationService::class, fn ($app): ModerationService => new ModerationService(
            $app->make(ReviewRepository::class),
            $app->make(RatingProjector::class),
            $app->make(\EruoFood\Shared\Domain\EventBus::class),
        ));

        $this->app->singleton(ReviewAnalyticsService::class, function ($app): ReviewAnalyticsService {
            /** @var list<string> $subjects */
            $subjects = (array) $app['config']->get('reviews.subjects', []);
            $types = [];
            foreach ($subjects as $value) {
                $type = SubjectType::tryFrom((string) $value);
                if ($type !== null) {
                    $types[] = $type;
                }
            }

            return new ReviewAnalyticsService(
                $app->make(ReviewStatsRepository::class),
                $app->make(RatingSummaryRepository::class),
                $types,
            );
        });

        // Event → verified-purchase ledger translator.
        $this->app->bind(EventTranslator::class, function ($app): EventTranslator {
            /** @var array<string, array{subject_type: string, subject_field: string, user_field: string}> $map */
            $map = (array) $app['config']->get('reviews.eligibility_events', []);

            return new EventTranslator($app->make(PurchaseEligibilityRepository::class), $map);
        });
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        // Subscribe the verified-purchase ledger to published order events (the
        // only inbound coupling — one-way, by event name).
        /** @var array<string, array{subject_type: string, subject_field: string, user_field: string}> $map */
        $map = (array) $this->app['config']->get('reviews.eligibility_events', []);
        (new DomainEventSubscriber($this->app->make(Dispatcher::class), $map))->register();
    }
}
