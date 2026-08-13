<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Provider;

use EruoFood\Identity\Application\Port\AuditRecorder;
use EruoFood\Identity\Application\Port\AuthNotifier;
use EruoFood\Identity\Application\Port\AvatarStorage;
use EruoFood\Identity\Application\Port\LoginChallenges;
use EruoFood\Identity\Application\Port\OAuthAccounts;
use EruoFood\Identity\Application\Port\OneTimeTokens;
use EruoFood\Identity\Application\Port\PasswordHasher;
use EruoFood\Identity\Application\Port\RefreshTokenManager;
use EruoFood\Identity\Application\Port\TokenIssuer;
use EruoFood\Identity\Application\Port\TwoFactorAuthenticator;
use EruoFood\Identity\Application\Service\AuthenticationService;
use EruoFood\Identity\Application\Service\PasswordService;
use EruoFood\Identity\Application\Service\RegistrationService;
use EruoFood\Identity\Application\Service\TokenService;
use EruoFood\Identity\Application\Service\TwoFactorService;
use EruoFood\Identity\Application\Service\UserPresenter;
use EruoFood\Identity\Contracts\UserDirectory;
use EruoFood\Identity\Domain\User\UserRepository;
use EruoFood\Identity\Infrastructure\Audit\DatabaseAuditRecorder;
use EruoFood\Identity\Infrastructure\Auth\Argon2PasswordHasher;
use EruoFood\Identity\Infrastructure\Auth\CacheLoginChallenges;
use EruoFood\Identity\Infrastructure\Auth\DatabaseOneTimeTokens;
use EruoFood\Identity\Infrastructure\Auth\EloquentRefreshTokenManager;
use EruoFood\Identity\Infrastructure\Auth\Google2FaAuthenticator;
use EruoFood\Identity\Infrastructure\Auth\JwtTokenIssuer;
use EruoFood\Identity\Infrastructure\Auth\Social\AppleAuthenticator;
use EruoFood\Identity\Infrastructure\Auth\Social\GoogleAuthenticator;
use EruoFood\Identity\Infrastructure\Contract\UserDirectoryAdapter;
use EruoFood\Identity\Infrastructure\Event\VerificationLevelProjectionSubscriber;
use EruoFood\Identity\Infrastructure\Mail\LaravelAuthNotifier;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\EloquentOAuthAccounts;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\EloquentUserRepository;
use EruoFood\Identity\Infrastructure\Storage\S3AvatarStorage;
use EruoFood\Identity\Interface\Http\Middleware\EnsureRole;
use EruoFood\Identity\Interface\Http\Middleware\JwtAuthenticate;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PragmaRX\Google2FA\Google2FA;

/**
 * Wires the Identity & Access module: binds every port to its adapter, assembles
 * the application services with their configuration, and registers routes,
 * migrations, mail views, and middleware. This is the module's composition root.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bindPorts();
        $this->bindServices();
    }

    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('auth.jwt', JwtAuthenticate::class);
        $router->aliasMiddleware('role', EnsureRole::class);

        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');
        $this->loadViewsFrom(__DIR__.'/../Mail/views', 'identity');

        // Project the account's assurance level from Verification. One-way, by
        // event name — Identity never queries the Verification context.
        (new VerificationLevelProjectionSubscriber())->register($this->app->make(Dispatcher::class));
    }

    private function bindPorts(): void
    {
        $config = $this->app->make(\Illuminate\Contracts\Config\Repository::class);

        $this->app->bind(UserRepository::class, fn (Application $app): EloquentUserRepository
            => new EloquentUserRepository($app->make(EventBus::class)));

        $this->app->bind(OAuthAccounts::class, EloquentOAuthAccounts::class);
        $this->app->bind(PasswordHasher::class, Argon2PasswordHasher::class);
        $this->app->bind(OneTimeTokens::class, DatabaseOneTimeTokens::class);

        // Published cross-module contract.
        $this->app->bind(UserDirectory::class, UserDirectoryAdapter::class);

        $this->app->bind(TokenIssuer::class, fn (): JwtTokenIssuer => new JwtTokenIssuer(
            secret: (string) ($config->get('identity.jwt.secret') ?: $config->get('app.key')),
            algo: (string) $config->get('identity.jwt.algo'),
            issuer: (string) $config->get('identity.jwt.issuer'),
            audience: (string) $config->get('identity.jwt.audience'),
            ttlMinutes: (int) $config->get('identity.jwt.ttl'),
        ));

        $this->app->bind(RefreshTokenManager::class, fn (): EloquentRefreshTokenManager
            => new EloquentRefreshTokenManager((int) $config->get('identity.refresh.ttl_days')));

        $this->app->bind(TwoFactorAuthenticator::class, fn (): Google2FaAuthenticator
            => new Google2FaAuthenticator(
                new Google2FA(),
                (string) $config->get('identity.two_factor.issuer'),
                (int) $config->get('identity.two_factor.window'),
            ));

        $this->app->bind(LoginChallenges::class, fn (Application $app): CacheLoginChallenges
            => new CacheLoginChallenges($app->make('cache.store')));

        $this->app->bind(AvatarStorage::class, fn (Application $app): S3AvatarStorage
            => new S3AvatarStorage($app->make('filesystem')->disk()));

        $this->app->bind(AuthNotifier::class, fn (Application $app): LaravelAuthNotifier
            => new LaravelAuthNotifier(
                $app->make('mailer'),
                (string) ($config->get('app.frontend_url') ?? 'http://localhost:5173'),
            ));

        $this->app->bind(AuditRecorder::class, fn (Application $app): DatabaseAuditRecorder
            => new DatabaseAuditRecorder($app->bound('request') ? $app->make('request') : null));
    }

    private function bindServices(): void
    {
        $config = $this->app->make(\Illuminate\Contracts\Config\Repository::class);

        $this->app->bind(RegistrationService::class, fn (Application $app): RegistrationService
            => new RegistrationService(
                $app->make(UserRepository::class),
                $app->make(PasswordHasher::class),
                $app->make(OneTimeTokens::class),
                $app->make(AuthNotifier::class),
                $app->make(TokenService::class),
                $app->make(AuditRecorder::class),
                (int) $config->get('identity.tokens.email_verification_ttl'),
            ));

        $this->app->bind(PasswordService::class, fn (Application $app): PasswordService
            => new PasswordService(
                $app->make(UserRepository::class),
                $app->make(PasswordHasher::class),
                $app->make(OneTimeTokens::class),
                $app->make(AuthNotifier::class),
                $app->make(RefreshTokenManager::class),
                $app->make(AuditRecorder::class),
                (int) $config->get('identity.tokens.password_reset_ttl'),
            ));

        $this->app->bind(TwoFactorService::class, fn (Application $app): TwoFactorService
            => new TwoFactorService(
                $app->make(UserRepository::class),
                $app->make(TwoFactorAuthenticator::class),
                $app->make(PasswordHasher::class),
                $app->make(AuditRecorder::class),
                (int) $config->get('identity.two_factor.recovery_code_count'),
            ));

        $this->app->bind(AuthenticationService::class, fn (Application $app): AuthenticationService
            => new AuthenticationService(
                $app->make(UserRepository::class),
                $app->make(PasswordHasher::class),
                $app->make(TwoFactorAuthenticator::class),
                $app->make(LoginChallenges::class),
                $app->make(RefreshTokenManager::class),
                $app->make(OAuthAccounts::class),
                $app->make(TokenService::class),
                $app->make(UserPresenter::class),
                $app->make(AuditRecorder::class),
                $this->socialProviders($app),
            ));
    }

    /**
     * @return array<string, \EruoFood\Identity\Application\Port\SocialAuthenticator>
     */
    private function socialProviders(Application $app): array
    {
        $config = $app->make(\Illuminate\Contracts\Config\Repository::class);

        return [
            'google' => new GoogleAuthenticator(
                $app->make(\Illuminate\Http\Client\Factory::class),
                $config->get('identity.providers.google.client_id'),
                (bool) $config->get('identity.providers.google.enabled'),
            ),
            'apple' => new AppleAuthenticator(
                (bool) $config->get('identity.providers.apple.enabled'),
            ),
        ];
    }
}
