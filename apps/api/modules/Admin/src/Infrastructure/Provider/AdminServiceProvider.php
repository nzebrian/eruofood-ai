<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Provider;

use EruoFood\Admin\Application\Port\UserDirectory;
use EruoFood\Admin\Application\Port\VendorDirectory;
use EruoFood\Admin\Application\Service\AuditService;
use EruoFood\Admin\Application\Service\EventAuditTranslator;
use EruoFood\Admin\Application\Service\PermissionService;
use EruoFood\Admin\Domain\Audit\AuditLogRepository;
use EruoFood\Admin\Domain\Cms\BannerRepository;
use EruoFood\Admin\Domain\Cms\CmsPageRepository;
use EruoFood\Admin\Domain\Cms\FaqRepository;
use EruoFood\Admin\Domain\Config\FeatureFlagRepository;
use EruoFood\Admin\Domain\Config\SettingRepository;
use EruoFood\Admin\Domain\Operations\ApprovalRequestRepository;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;
use EruoFood\Admin\Domain\Rbac\ImpersonationRepository;
use EruoFood\Admin\Domain\Support\TicketRepository;
use EruoFood\Admin\Infrastructure\Directory\IdentityUserDirectory;
use EruoFood\Admin\Infrastructure\Directory\MarketplaceVendorDirectory;
use EruoFood\Admin\Infrastructure\Event\DomainEventSubscriber;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\EloquentAdminAccountRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\EloquentApprovalRequestRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\EloquentAuditLogRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\EloquentBannerRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\EloquentCmsPageRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\EloquentFaqRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\EloquentFeatureFlagRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\EloquentImpersonationRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\EloquentSettingRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\EloquentTicketRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the Platform Administration, CMS & Operations context.
 * Binds the RBAC/CMS/config/ops/support/audit repositories to their Eloquent
 * adapters, the read-only directory ports to soft-reference adapters over the
 * Identity and Marketplace tables, the config-driven permission service, and
 * the event → audit translator. It also registers the domain-event subscriber
 * that feeds cross-context events into the audit trail — the only inbound
 * coupling, one-way and by event name.
 */
final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make(\Illuminate\Contracts\Config\Repository::class);

        // Repositories → Eloquent adapters.
        $this->app->bind(AdminAccountRepository::class, EloquentAdminAccountRepository::class);
        $this->app->bind(ImpersonationRepository::class, EloquentImpersonationRepository::class);
        $this->app->bind(AuditLogRepository::class, EloquentAuditLogRepository::class);
        $this->app->bind(CmsPageRepository::class, EloquentCmsPageRepository::class);
        $this->app->bind(BannerRepository::class, EloquentBannerRepository::class);
        $this->app->bind(FaqRepository::class, EloquentFaqRepository::class);
        $this->app->bind(SettingRepository::class, EloquentSettingRepository::class);
        $this->app->bind(FeatureFlagRepository::class, EloquentFeatureFlagRepository::class);
        $this->app->bind(ApprovalRequestRepository::class, EloquentApprovalRequestRepository::class);
        $this->app->bind(TicketRepository::class, EloquentTicketRepository::class);

        // Read-only directory ports over other contexts (soft references).
        $this->app->bind(UserDirectory::class, IdentityUserDirectory::class);
        $this->app->bind(VendorDirectory::class, MarketplaceVendorDirectory::class);

        // Authorisation core — config bootstraps for super admins.
        $this->app->singleton(PermissionService::class, function ($app) use ($config): PermissionService {
            /** @var list<string> $bootstrap */
            $bootstrap = (array) $config->get('admin.bootstrap_super_admins', []);

            return new PermissionService(
                $app->make(AdminAccountRepository::class),
                $bootstrap,
                (bool) $config->get('admin.identity_admin_is_super', true),
            );
        });

        // Event → audit translator (config-driven audit map).
        $this->app->bind(EventAuditTranslator::class, function ($app) use ($config): EventAuditTranslator {
            /** @var array<string, string> $map */
            $map = (array) $config->get('admin.audit_events', []);

            return new EventAuditTranslator($app->make(AuditService::class), $map);
        });
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        // Subscribe to published domain events for the audit trail (the only
        // inbound coupling — one-way, by event name).
        /** @var array<string, string> $map */
        $map = (array) $this->app->make(\Illuminate\Contracts\Config\Repository::class)->get('admin.audit_events', []);
        (new DomainEventSubscriber($this->app->make(Dispatcher::class), $map))->register();
    }
}
