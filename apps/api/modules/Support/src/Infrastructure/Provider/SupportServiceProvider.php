<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Provider;

use EruoFood\Ai\Contracts\AiAdvisor;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Support\Application\Port\AiSupportAssistant;
use EruoFood\Support\Application\Service\CrmService;
use EruoFood\Support\Application\Service\EventTranslator;
use EruoFood\Support\Application\Service\SlaService;
use EruoFood\Support\Domain\Automation\AutomationRuleRepository;
use EruoFood\Support\Domain\Crm\CustomerProfileRepository;
use EruoFood\Support\Domain\Crm\InteractionRepository;
use EruoFood\Support\Domain\Csat\CsatRepository;
use EruoFood\Support\Domain\Knowledge\ArticleRepository;
use EruoFood\Support\Domain\Sla\SlaPolicyRepository;
use EruoFood\Support\Domain\Ticket\SupportStatsRepository;
use EruoFood\Support\Domain\Ticket\TicketRepository;
use EruoFood\Support\Infrastructure\Ai\AiBackedSupportAssistant;
use EruoFood\Support\Infrastructure\Ai\HeuristicSupportAssistant;
use EruoFood\Support\Infrastructure\Console\ScanSlaCommand;
use EruoFood\Support\Infrastructure\Event\DomainEventSubscriber;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\EloquentArticleRepository;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\EloquentAutomationRuleRepository;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\EloquentCsatRepository;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\EloquentCustomerProfileRepository;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\EloquentInteractionRepository;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\EloquentSlaPolicyRepository;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\EloquentSupportStatsRepository;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\EloquentTicketRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the Customer Support, Helpdesk & CRM context. Binds the
 * ticket/SLA/knowledge-base/CRM/automation/CSAT repositories, the SLA and CRM
 * services (config-driven), the agent-assist adapter, and the SLA-scan command;
 * and subscribes the CRM to published domain events — the only inbound coupling,
 * one-way and by name. No business module manages tickets; all support flows
 * through this context.
 */
final class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app['config'];

        // Repositories → Eloquent adapters.
        $this->app->singleton(TicketRepository::class, fn ($app): TicketRepository => new EloquentTicketRepository(
            (string) $app['config']->get('support.ref_prefix', 'EF'),
        ));
        $this->app->bind(SlaPolicyRepository::class, EloquentSlaPolicyRepository::class);
        $this->app->bind(ArticleRepository::class, EloquentArticleRepository::class);
        $this->app->bind(CustomerProfileRepository::class, EloquentCustomerProfileRepository::class);
        $this->app->bind(InteractionRepository::class, EloquentInteractionRepository::class);
        $this->app->bind(AutomationRuleRepository::class, EloquentAutomationRuleRepository::class);
        $this->app->bind(CsatRepository::class, EloquentCsatRepository::class);
        $this->app->bind(SupportStatsRepository::class, EloquentSupportStatsRepository::class);

        // Agent-assist — AI-backed when enabled and available, else offline heuristic.
        $this->app->singleton(AiSupportAssistant::class, function ($app): AiSupportAssistant {
            $heuristic = new HeuristicSupportAssistant();
            if ((bool) $app['config']->get('support.ai_assist', false) && $app->bound(AiAdvisor::class)) {
                return new AiBackedSupportAssistant($app->make(AiAdvisor::class), $heuristic);
            }

            return $heuristic;
        });

        // SLA service (escalate-on-breach flag).
        $this->app->singleton(SlaService::class, fn ($app): SlaService => new SlaService(
            $app->make(TicketRepository::class),
            $app->make(SlaPolicyRepository::class),
            $app->make(EventBus::class),
            (bool) $app['config']->get('support.escalate_on_breach', true),
        ));

        // CRM (segmentation thresholds).
        $this->app->singleton(CrmService::class, function ($app): CrmService {
            /** @var array<string, int> $segments */
            $segments = (array) $app['config']->get('support.segments', []);

            return new CrmService(
                $app->make(CustomerProfileRepository::class),
                $app->make(InteractionRepository::class),
                $app->make(AiSupportAssistant::class),
                $segments,
            );
        });

        // Event → CRM timeline translator.
        $this->app->bind(EventTranslator::class, function ($app): EventTranslator {
            /** @var array<string, string> $map */
            $map = (array) $app['config']->get('support.timeline_events', []);

            return new EventTranslator($app->make(CrmService::class), $map);
        });

        $this->commands([ScanSlaCommand::class]);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        // Subscribe to published domain events for the CRM timeline (the only
        // inbound coupling — one-way, by event name).
        /** @var array<string, string> $map */
        $map = (array) $this->app['config']->get('support.timeline_events', []);
        (new DomainEventSubscriber($this->app->make(Dispatcher::class), $map))->register();
    }
}
