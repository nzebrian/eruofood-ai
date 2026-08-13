<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Provider;

use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Verification\Application\Port\BusinessRegistryProvider;
use EruoFood\Verification\Application\Port\BusinessRegistryRegistry;
use EruoFood\Verification\Application\Port\FieldEncryptor;
use EruoFood\Verification\Application\Port\IdentityVerificationProvider;
use EruoFood\Verification\Application\Port\PhoneVerificationSender;
use EruoFood\Verification\Application\Port\SensitiveAccessLogger;
use EruoFood\Verification\Application\Port\VerificationProviderRegistry;
use EruoFood\Verification\Application\Service\EligibilityService;
use EruoFood\Verification\Application\Service\PhoneVerificationService;
use EruoFood\Verification\Application\Service\ReconciliationService;
use EruoFood\Verification\Application\Service\StepUpService;
use EruoFood\Verification\Application\Service\VerificationService;
use EruoFood\Verification\Contracts\StepUpGuard;
use EruoFood\Verification\Contracts\VerificationStatusQuery;
use EruoFood\Verification\Domain\Attempt\AttemptRepository;
use EruoFood\Verification\Domain\Business\BusinessProfileRepository;
use EruoFood\Verification\Domain\Document\DocumentMetadataRepository;
use EruoFood\Verification\Domain\Phone\PhoneChallengeRepository;
use EruoFood\Verification\Domain\VerificationCase\CaseRepository;
use EruoFood\Verification\Domain\Webhook\WebhookEventRepository;
use EruoFood\Verification\Infrastructure\Console\PurgeVerificationDataCommand;
use EruoFood\Verification\Infrastructure\Console\ReconcileVerificationsCommand;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\EloquentAttemptRepository;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\EloquentBusinessProfileRepository;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\EloquentCaseRepository;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\EloquentDocumentMetadataRepository;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\EloquentPhoneChallengeRepository;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\EloquentVerificationStatusQuery;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\EloquentWebhookEventRepository;
use EruoFood\Verification\Infrastructure\Provider\Didit\DiditProvider;
use EruoFood\Verification\Infrastructure\Provider\Manual\ManualReviewProvider;
use EruoFood\Verification\Infrastructure\Provider\Mock\MockProvider;
use EruoFood\Verification\Infrastructure\Provider\Registry\CacRegistryProvider;
use EruoFood\Verification\Infrastructure\Provider\Registry\MockRegistryProvider;
use EruoFood\Verification\Infrastructure\Registry\ConfigBusinessRegistryRegistry;
use EruoFood\Verification\Infrastructure\Registry\ConfigProviderRegistry;
use EruoFood\Verification\Infrastructure\Security\AuditingSensitiveAccessLogger;
use EruoFood\Verification\Infrastructure\Security\LaravelFieldEncryptor;
use EruoFood\Verification\Infrastructure\Security\NullPhoneVerificationSender;
use EruoFood\Verification\Infrastructure\StepUp\ConfiguredStepUpGuard;
use EruoFood\Verification\Interface\Http\Controller\BusinessVerificationController;
use EruoFood\Verification\Interface\Http\Middleware\RequiresVerificationLevel;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Wires the Verification context.
 *
 * The provider factories are closures rather than eager constructions, so
 * configuring a provider does not instantiate it and an unconfigured one is only
 * missed if something actually asks for it. Adding a second identity provider or
 * a second country's registry is an entry in these maps plus an adapter — no
 * change reaches the domain.
 */
final class VerificationServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        CaseRepository::class => EloquentCaseRepository::class,
        AttemptRepository::class => EloquentAttemptRepository::class,
        WebhookEventRepository::class => EloquentWebhookEventRepository::class,
        VerificationStatusQuery::class => EloquentVerificationStatusQuery::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../../config/verification.php', 'verification');

        /** @var Config $config */
        $config = $this->app->make('config');

        $this->app->singleton(FieldEncryptor::class, fn (): LaravelFieldEncryptor
            => new LaravelFieldEncryptor($this->app->make(Encrypter::class)));

        $this->app->singleton(SensitiveAccessLogger::class, fn (): AuditingSensitiveAccessLogger
            => new AuditingSensitiveAccessLogger(
                $this->app->make(EventBus::class),
                $this->app->bound('request') ? $this->app->make('request') : null,
            ));

        $this->app->singleton(PhoneVerificationSender::class, fn (): NullPhoneVerificationSender
            => new NullPhoneVerificationSender($this->app->make(LoggerInterface::class)));

        $this->app->bind(DocumentMetadataRepository::class, fn (): EloquentDocumentMetadataRepository
            => new EloquentDocumentMetadataRepository($this->app->make(FieldEncryptor::class)));

        $this->app->bind(BusinessProfileRepository::class, fn (): EloquentBusinessProfileRepository
            => new EloquentBusinessProfileRepository($this->app->make(FieldEncryptor::class)));

        $this->app->bind(PhoneChallengeRepository::class, EloquentPhoneChallengeRepository::class);

        $this->registerProviders($config);
        $this->registerRegistries($config);
        $this->registerServices($config);
    }

    private function registerProviders(Config $config): void
    {
        $this->app->singleton(VerificationProviderRegistry::class, function () use ($config): ConfigProviderRegistry {
            /** @var array<string, mixed> $providers */
            $providers = (array) $config->get('verification.providers', []);

            /** @var array<string, callable():IdentityVerificationProvider> $factories */
            $factories = [
                'didit' => fn (): DiditProvider => new DiditProvider(
                    $this->app->make(HttpFactory::class),
                    (array) ($providers['didit'] ?? []),
                ),
                'mock' => fn (): MockProvider => new MockProvider((array) ($providers['mock'] ?? [])),
                'manual' => fn (): ManualReviewProvider => new ManualReviewProvider(),
                // The CAC registry answers business questions; when it is named
                // as a *provider* the manual route carries the case, because the
                // decision comes from the registry lookup rather than a hosted
                // provider session.
                'cac' => fn (): ManualReviewProvider => new ManualReviewProvider(),
            ];

            return new ConfigProviderRegistry(
                $factories,
                (array) $config->get('verification.routing', []),
            );
        });
    }

    private function registerRegistries(Config $config): void
    {
        $this->app->singleton(BusinessRegistryRegistry::class, function () use ($config): ConfigBusinessRegistryRegistry {
            /** @var array<string, mixed> $registries */
            $registries = (array) $config->get('verification.registries', []);

            /** @var array<string, callable():BusinessRegistryProvider> $factories */
            $factories = [];

            foreach ($registries as $country => $settings) {
                $countryCode = strtoupper((string) $country);
                $adapter = (string) (((array) $settings)['adapter'] ?? '');

                $factories[$countryCode] = match ($adapter) {
                    'cac' => fn (): CacRegistryProvider => new CacRegistryProvider(
                        $this->app->make(HttpFactory::class),
                        (array) $settings,
                    ),
                    default => fn (): MockRegistryProvider => new MockRegistryProvider($countryCode),
                };
            }

            // Under test the registry is deterministic and offline, matching how
            // the identity provider behaves, so the suite never leaves the process.
            if ($this->app->environment('testing')) {
                $factories['NG'] = fn (): MockRegistryProvider => new MockRegistryProvider('NG');
            }

            return new ConfigBusinessRegistryRegistry($factories);
        });
    }

    private function registerServices(Config $config): void
    {
        $this->app->singleton(StepUpService::class, fn (): StepUpService
            => new StepUpService((array) $config->get('verification.step_up', [])));

        $this->app->singleton(EligibilityService::class, fn (): EligibilityService
            => new EligibilityService(
                $this->app->make(CaseRepository::class),
                (array) $config->get('verification.enforcement', []),
            ));

        $this->app->singleton(PhoneVerificationService::class, fn (): PhoneVerificationService
            => new PhoneVerificationService(
                $this->app->make(PhoneChallengeRepository::class),
                $this->app->make(PhoneVerificationSender::class),
                $this->app->make(EventBus::class),
                $this->app->make(TransactionManager::class),
                $this->app->make(Clock::class),
                (int) $config->get('verification.phone.code_ttl_seconds', 600),
                (int) $config->get('verification.phone.max_attempts', 5),
            ));

        // The published guard other contexts bind to. Joins "what does this
        // operation demand" to "what does this account hold"; neither consumer
        // nor Payments ever reaches into Verification to work that out.
        $this->app->singleton(StepUpGuard::class, fn (): ConfiguredStepUpGuard
            => new ConfiguredStepUpGuard(
                $this->app->make(StepUpService::class),
                $this->app->make(VerificationStatusQuery::class),
            ));

        $this->app->when(VerificationService::class)
            ->needs('$identityValidityDays')
            ->give(fn (): int => (int) $config->get('verification.lifecycle.identity_validity_days', 730));

        $this->app->when(VerificationService::class)
            ->needs('$businessValidityDays')
            ->give(fn (): int => (int) $config->get('verification.lifecycle.business_validity_days', 365));

        $this->app->when(ReconciliationService::class)
            ->needs('$reconcileAfterMinutes')
            ->give(fn (): int => (int) $config->get('verification.lifecycle.reconcile_after_minutes', 30));

        /*
         * Business ownership is a fact the owning context holds, so the check is
         * injected as a closure rather than imported. Verification asks
         * Marketplace or Commerce "does this user own this business?" through
         * their own read models and takes no compile-time dependency on either.
         */
        $this->app->when(BusinessVerificationController::class)
            ->needs('$ownsBusiness')
            ->give(fn (): callable => function (string $kind, string $businessId, string $userId): bool {
                $table = $kind === 'grocery' ? 'commerce_stores' : 'marketplace_vendors';

                return \Illuminate\Support\Facades\DB::table($table)
                    ->where('id', $businessId)
                    ->where('owner_user_id', $userId)
                    ->exists();
            });

        /*
         * The mirror of the ownership check: who owns this business, so a case
         * about a company knows which account to write to. Same reasoning — the
         * fact lives in Marketplace and Commerce, and Verification asks for it
         * rather than importing either. A business id is unique across both
         * tables, so whichever answers first is the owner.
         */
        $this->app->when(VerificationService::class)
            ->needs('$businessContact')
            ->give(fn (): callable => function (string $businessId): ?string {
                foreach (['marketplace_vendors', 'commerce_stores'] as $table) {
                    $owner = \Illuminate\Support\Facades\DB::table($table)
                        ->where('id', $businessId)
                        ->value('owner_user_id');

                    if (is_string($owner) && $owner !== '') {
                        return $owner;
                    }
                }

                return null;
            });
    }

    public function boot(): void
    {
        $this->app->make(Router::class)
            ->aliasMiddleware('requires.verification', RequiresVerificationLevel::class);

        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileVerificationsCommand::class,
                PurgeVerificationDataCommand::class,
            ]);
        }
    }
}
