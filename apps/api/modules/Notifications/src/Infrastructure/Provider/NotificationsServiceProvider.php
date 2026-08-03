<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Provider;

use EruoFood\Notifications\Application\Port\PresenceRepository;
use EruoFood\Notifications\Application\Port\RealtimeBroadcaster;
use EruoFood\Notifications\Application\Service\ChannelDispatcher;
use EruoFood\Notifications\Application\Service\EventTranslator;
use EruoFood\Notifications\Application\Service\NotificationService;
use EruoFood\Notifications\Application\Service\PreferenceService;
use EruoFood\Notifications\Domain\Broadcast\AudienceProvider;
use EruoFood\Notifications\Domain\Broadcast\BroadcastRepository;
use EruoFood\Notifications\Domain\Messaging\ConversationRepository;
use EruoFood\Notifications\Domain\Messaging\MessageRepository;
use EruoFood\Notifications\Domain\Notification\DeliveryStatsRepository;
use EruoFood\Notifications\Domain\Notification\NotificationRepository;
use EruoFood\Notifications\Domain\Preference\NotificationPreferenceRepository;
use EruoFood\Notifications\Domain\Template\NotificationTemplateRepository;
use EruoFood\Notifications\Domain\ValueObject\QuietHours;
use EruoFood\Notifications\Infrastructure\Broadcast\IdentityAudienceProvider;
use EruoFood\Notifications\Infrastructure\Channel\EmailChannelSender;
use EruoFood\Notifications\Infrastructure\Channel\InAppChannelSender;
use EruoFood\Notifications\Infrastructure\Channel\PushChannelSender;
use EruoFood\Notifications\Infrastructure\Channel\SmsChannelSender;
use EruoFood\Notifications\Infrastructure\Channel\TelegramChannelSender;
use EruoFood\Notifications\Infrastructure\Channel\WhatsAppChannelSender;
use EruoFood\Notifications\Infrastructure\Event\DomainEventSubscriber;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\EloquentBroadcastRepository;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\EloquentConversationRepository;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\EloquentMessageRepository;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\EloquentNotificationPreferenceRepository;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\EloquentNotificationRepository;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\EloquentNotificationTemplateRepository;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\EloquentPresenceRepository;
use EruoFood\Notifications\Infrastructure\Realtime\BroadcastingRealtimeBroadcaster;
use EruoFood\Notifications\Infrastructure\Realtime\LogRealtimeBroadcaster;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Composition root for the Notifications, Messaging & Real-Time Communication
 * context. Binds repositories, the channel dispatcher (only enabled channels),
 * the real-time broadcaster, the audience provider and the event translator;
 * and — crucially — registers the domain-event subscriber that turns published
 * events into notifications. No business module ever calls this context.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make(\Illuminate\Contracts\Config\Repository::class);
        $language = (string) $config->get('notifications.default_language', 'en');
        $maxAttempts = (int) $config->get('notifications.retry.max_attempts', 3);
        /** @var array<string, bool> $channels */
        $channels = (array) $config->get('notifications.channels', []);
        /** @var array<string, mixed> $quiet */
        $quiet = (array) $config->get('notifications.quiet_hours', []);
        $quietHours = new QuietHours(
            (bool) ($quiet['enabled_by_default'] ?? false),
            (string) ($quiet['start'] ?? '22:00'),
            (string) ($quiet['end'] ?? '07:00'),
        );

        // Repositories → Eloquent adapters.
        $this->app->bind(NotificationRepository::class, EloquentNotificationRepository::class);
        $this->app->bind(DeliveryStatsRepository::class, EloquentNotificationRepository::class);
        $this->app->bind(NotificationTemplateRepository::class, EloquentNotificationTemplateRepository::class);
        $this->app->bind(NotificationPreferenceRepository::class, EloquentNotificationPreferenceRepository::class);
        $this->app->bind(ConversationRepository::class, EloquentConversationRepository::class);
        $this->app->bind(MessageRepository::class, EloquentMessageRepository::class);
        $this->app->bind(BroadcastRepository::class, EloquentBroadcastRepository::class);
        $this->app->bind(PresenceRepository::class, EloquentPresenceRepository::class);
        $this->app->bind(AudienceProvider::class, IdentityAudienceProvider::class);

        // Real-time broadcaster — log by default, Reverb/broadcasting when enabled.
        $this->app->singleton(RealtimeBroadcaster::class, function ($app) use ($config): RealtimeBroadcaster {
            $driver = (string) $config->get('notifications.realtime.driver', 'log');

            return $driver === 'log'
                ? new LogRealtimeBroadcaster($app->make(LoggerInterface::class))
                : new BroadcastingRealtimeBroadcaster($app->make(\Illuminate\Contracts\Broadcasting\Factory::class));
        });

        // Channel dispatcher — only the enabled channels.
        $this->app->singleton(ChannelDispatcher::class, function ($app) use ($channels): ChannelDispatcher {
            $log = $app->make(LoggerInterface::class);
            $senders = [];
            if ($channels['email'] ?? false) {
                $senders[] = new EmailChannelSender($log);
            }
            if ($channels['sms'] ?? false) {
                $senders[] = new SmsChannelSender($log);
            }
            if ($channels['push'] ?? false) {
                $senders[] = new PushChannelSender($log);
            }
            $senders[] = new InAppChannelSender(); // always on
            if ($channels['whatsapp'] ?? false) {
                $senders[] = new WhatsAppChannelSender($log);
            }
            if ($channels['telegram'] ?? false) {
                $senders[] = new TelegramChannelSender($log);
            }

            return new ChannelDispatcher($senders);
        });

        // The event translator (config-driven event → notification map).
        $this->app->bind(EventTranslator::class, function ($app) use ($config): EventTranslator {
            /** @var array<string, array{category: string, template: string, channels: list<string>, recipient: list<string>}> $map */
            $map = (array) $config->get('notifications.event_map', []);

            return new EventTranslator($app->make(NotificationService::class), $map);
        });

        // Contextual primitives.
        foreach ([NotificationService::class, PreferenceService::class] as $needs) {
            $this->app->when($needs)->needs('$defaultLanguage')->give($language);
            // QuietHours is a typed constructor dependency, so the contextual
            // binding must match by type (a name-based '$defaultQuietHours'
            // binding is never consulted for a class-typed parameter).
            $this->app->when($needs)->needs(QuietHours::class)->give(fn (): QuietHours => $quietHours);
        }
        $this->app->when(NotificationService::class)->needs('$maxAttempts')->give($maxAttempts);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        // Subscribe to published domain events (the only inbound coupling).
        /** @var array<string, mixed> $map */
        $map = (array) $this->app->make(\Illuminate\Contracts\Config\Repository::class)->get('notifications.event_map', []);
        (new DomainEventSubscriber($this->app->make(Dispatcher::class), $map))->register();
    }
}
