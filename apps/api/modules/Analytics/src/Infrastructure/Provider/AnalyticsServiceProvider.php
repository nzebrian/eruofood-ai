<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Provider;

use EruoFood\Analytics\Application\Port\ReportDelivery;
use EruoFood\Analytics\Application\Port\ReportExporter;
use EruoFood\Analytics\Application\Service\EventTranslator;
use EruoFood\Analytics\Application\Service\EventCollectionService;
use EruoFood\Analytics\Domain\Metric\AnalyticsEventRepository;
use EruoFood\Analytics\Domain\Metric\MetricRepository;
use EruoFood\Analytics\Domain\Report\ReportRepository;
use EruoFood\Analytics\Domain\Report\ScheduledReportRepository;
use EruoFood\Analytics\Infrastructure\Event\DomainEventSubscriber;
use EruoFood\Analytics\Infrastructure\Export\LoggingReportDelivery;
use EruoFood\Analytics\Infrastructure\Export\NativeReportExporter;
use EruoFood\Analytics\Infrastructure\Persistence\Eloquent\EloquentAnalyticsEventRepository;
use EruoFood\Analytics\Infrastructure\Persistence\Eloquent\EloquentMetricRepository;
use EruoFood\Analytics\Infrastructure\Persistence\Eloquent\EloquentReportRepository;
use EruoFood\Analytics\Infrastructure\Persistence\Eloquent\EloquentScheduledReportRepository;
use EruoFood\Analytics\Interface\Http\Controller\DashboardController;
use EruoFood\Analytics\Interface\Http\Controller\ReportController;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Composition root for the Analytics, Business Intelligence & Reporting context.
 * Binds repositories, the report exporter and delivery ports, and the event
 * translator; and registers the domain-event subscriber that collects published
 * events into analytics. No business module writes here.
 */
final class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app['config'];

        $this->app->bind(AnalyticsEventRepository::class, EloquentAnalyticsEventRepository::class);
        $this->app->bind(MetricRepository::class, EloquentMetricRepository::class);
        $this->app->bind(ReportRepository::class, EloquentReportRepository::class);
        $this->app->bind(ScheduledReportRepository::class, EloquentScheduledReportRepository::class);

        $this->app->bind(ReportExporter::class, NativeReportExporter::class);
        $this->app->bind(ReportDelivery::class, fn ($app): LoggingReportDelivery => new LoggingReportDelivery($app->make(LoggerInterface::class)));

        $this->app->bind(EventTranslator::class, function ($app) use ($config): EventTranslator {
            /** @var array<string, array{metric: string, category: string, op: string, value_key?: string, dimensions: list<string>}> $map */
            $map = (array) $config->get('analytics.event_map', []);

            return new EventTranslator($app->make(EventCollectionService::class), $map);
        });

        $defaultDays = (int) $config->get('analytics.dashboard.default_days', 30);
        foreach ([DashboardController::class, ReportController::class] as $controller) {
            $this->app->when($controller)->needs('$defaultDays')->give($defaultDays);
        }
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        /** @var array<string, mixed> $map */
        $map = (array) $this->app['config']->get('analytics.event_map', []);
        (new DomainEventSubscriber($this->app->make(Dispatcher::class), $map))->register();
    }
}
