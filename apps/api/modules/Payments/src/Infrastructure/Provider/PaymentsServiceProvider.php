<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Provider;

use EruoFood\Payments\Application\Port\CommissionCalculator;
use EruoFood\Payments\Application\Port\FieldEncryptor;
use EruoFood\Payments\Application\Port\FraudDetector;
use EruoFood\Payments\Application\Port\PaymentGatewayFactory;
use EruoFood\Payments\Application\Port\PaymentNotifier;
use EruoFood\Payments\Application\Service\FinancialReportService;
use EruoFood\Payments\Application\Service\PaymentService;
use EruoFood\Payments\Application\Service\RefundService;
use EruoFood\Payments\Application\Service\SettlementService;
use EruoFood\Payments\Application\Service\SubscriptionService;
use EruoFood\Payments\Application\Service\WalletService;
use EruoFood\Payments\Contracts\PaymentInitiator;
use EruoFood\Payments\Domain\Ledger\IdentityGenerator;
use EruoFood\Payments\Domain\Ledger\LedgerRepository;
use EruoFood\Payments\Domain\Method\SavedPaymentMethodRepository;
use EruoFood\Payments\Domain\Payment\PaymentRepository;
use EruoFood\Payments\Domain\Payment\RefundRepository;
use EruoFood\Payments\Domain\Settlement\PayoutRepository;
use EruoFood\Payments\Domain\Settlement\SettlementRepository;
use EruoFood\Payments\Domain\Subscription\SubscriptionRepository;
use EruoFood\Payments\Domain\Wallet\WalletRepository;
use EruoFood\Payments\Domain\Webhook\WebhookEventRepository;
use EruoFood\Payments\Infrastructure\Commission\ConfigCommissionCalculator;
use EruoFood\Payments\Infrastructure\Console\VerifyLedgerCommand;
use EruoFood\Payments\Infrastructure\Notification\LoggingPaymentNotifier;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\EloquentLedgerRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\EloquentPaymentRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\EloquentPayoutRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\EloquentRefundRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\EloquentSavedPaymentMethodRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\EloquentSettlementRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\EloquentSubscriptionRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\EloquentWalletRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\EloquentWebhookEventRepository;
use EruoFood\Payments\Infrastructure\Persistence\Support\UuidIdentityGenerator;
use EruoFood\Payments\Infrastructure\Security\AllowAllFraudDetector;
use EruoFood\Payments\Infrastructure\Security\LaravelFieldEncryptor;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Composition root for the Payments, Wallet & Financial Services context.
 *
 * Binds every repository port to its Eloquent adapter; the provider-abstraction
 * factory, commission engine, fraud hook, notifier and field encryptor to their
 * implementations; and the published {@see PaymentInitiator} contract to the
 * payment service so other contexts can start payments without coupling.
 * Currency, escrow policy and commission rates come from config as contextual
 * primitives.
 */
final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make(\Illuminate\Contracts\Config\Repository::class);
        $currency = (string) $config->get('payments.currency', 'NGN');
        $lowBalance = (int) $config->get('payments.wallet.low_balance_minor', 50000);

        // Repositories → Eloquent adapters.
        $this->app->bind(PaymentRepository::class, EloquentPaymentRepository::class);
        $this->app->bind(RefundRepository::class, EloquentRefundRepository::class);
        $this->app->bind(LedgerRepository::class, EloquentLedgerRepository::class);
        $this->app->bind(WalletRepository::class, EloquentWalletRepository::class);
        $this->app->bind(SettlementRepository::class, EloquentSettlementRepository::class);
        $this->app->bind(PayoutRepository::class, EloquentPayoutRepository::class);
        $this->app->bind(SavedPaymentMethodRepository::class, EloquentSavedPaymentMethodRepository::class);
        $this->app->bind(SubscriptionRepository::class, EloquentSubscriptionRepository::class);
        $this->app->bind(WebhookEventRepository::class, EloquentWebhookEventRepository::class);

        // Ports → adapters.
        $this->app->bind(IdentityGenerator::class, UuidIdentityGenerator::class);
        $this->app->bind(FraudDetector::class, AllowAllFraudDetector::class);
        $this->app->bind(PaymentNotifier::class, fn ($app): LoggingPaymentNotifier => new LoggingPaymentNotifier($app->make(LoggerInterface::class)));
        $this->app->bind(FieldEncryptor::class, fn ($app): LaravelFieldEncryptor => new LaravelFieldEncryptor($app->make(Encrypter::class)));

        $this->app->singleton(PaymentGatewayFactory::class, function ($app) use ($config): GatewayFactory {
            /** @var array<string, mixed> $conf */
            $conf = $config->get('payments');

            return new GatewayFactory($app->make(HttpFactory::class), $conf);
        });

        $this->app->bind(CommissionCalculator::class, function () use ($config): ConfigCommissionCalculator {
            return new ConfigCommissionCalculator(
                (int) $config->get('payments.commission.rate_bps', 1000),
                (int) $config->get('payments.commission.flat_fee_minor', 0),
            );
        });

        // The published contract → the payment service.
        $this->app->bind(PaymentInitiator::class, PaymentService::class);

        // Contextual primitives.
        foreach ([
            EloquentPaymentRepository::class, EloquentRefundRepository::class, EloquentLedgerRepository::class,
            EloquentWalletRepository::class, EloquentSettlementRepository::class, EloquentPayoutRepository::class,
            EloquentSubscriptionRepository::class, RefundService::class, WalletService::class,
            SettlementService::class, SubscriptionService::class, FinancialReportService::class,
            \EruoFood\Payments\Interface\Http\Controller\PaymentController::class,
            \EruoFood\Payments\Interface\Http\Controller\WalletController::class,
        ] as $needsCurrency) {
            $this->app->when($needsCurrency)->needs('$currency')->give($currency);
        }
        $this->app->when(WalletService::class)->needs('$lowBalanceThreshold')->give(fn () => $lowBalance);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyLedgerCommand::class]);
        }
    }
}
