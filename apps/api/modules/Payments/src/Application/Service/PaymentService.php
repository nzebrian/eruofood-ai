<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use DateTimeImmutable;
use EruoFood\Payments\Application\DTO\GatewayCharge;
use EruoFood\Payments\Application\DTO\OpenedPayment;
use EruoFood\Payments\Application\Input\InitiatePaymentInput;
use EruoFood\Payments\Application\Port\CommissionCalculator;
use EruoFood\Payments\Application\Port\FraudDetector;
use EruoFood\Payments\Application\Port\PaymentGatewayFactory;
use EruoFood\Payments\Application\Port\PaymentNotifier;
use EruoFood\Payments\Contracts\InitiatePaymentRequest;
use EruoFood\Payments\Contracts\PaymentInitiator;
use EruoFood\Payments\Contracts\PaymentIntent;
use EruoFood\Payments\Domain\Enum\PaymentMethodType;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Enum\PaymentStatus;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Exception\PaymentsNotFound;
use EruoFood\Payments\Domain\Payment\Payment;
use EruoFood\Payments\Domain\Payment\PaymentRepository;
use EruoFood\Payments\Domain\ValueObject\PaymentSplit;
use EruoFood\Payments\Domain\ValueObject\ProviderReference;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Str;

/**
 * Orchestrates the payment lifecycle and implements the published
 * {@see PaymentInitiator} contract other contexts use. It is **idempotent** on
 * the caller's key, consults the fraud hook, routes through the provider
 * abstraction (Strategy + Factory), and on capture posts the double-entry
 * ledger (commission/fees/escrow) and publishes domain events — never calling
 * the Order module back directly.
 */
final readonly class PaymentService implements PaymentInitiator
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGatewayFactory $gateways,
        private CommissionCalculator $commission,
        private FraudDetector $fraud,
        private LedgerService $ledger,
        private PaymentNotifier $notifier,
        private EventBus $events,
        private bool $escrowEnabled,
    ) {
    }

    public function initiate(InitiatePaymentRequest $request): PaymentIntent
    {
        $splits = array_map(
            static fn (array $s): PaymentSplit => PaymentSplit::fromArray($s, $request->currency),
            $request->splits,
        );

        $opened = $this->open(new InitiatePaymentInput(
            payerUserId: $request->payerUserId,
            customerEmail: $request->customerEmail,
            amount: new Money($request->amountMinor, $request->currency),
            orderId: $request->orderId,
            methodType: PaymentMethodType::from($request->methodType),
            provider: $request->provider !== null ? PaymentProvider::from($request->provider) : null,
            idempotencyKey: $request->idempotencyKey,
            splits: array_values($splits),
        ), '0.0.0.0');

        return new PaymentIntent(
            $opened->payment->id(),
            $opened->payment->reference(),
            $opened->payment->status()->value,
            $opened->authorizationUrl,
            $opened->payment->provider()->value,
        );
    }

    /** Start a payment (idempotent). Capture happens here (mock), or later on verify/webhook. */
    public function open(InitiatePaymentInput $input, string $ipAddress): OpenedPayment
    {
        if ($input->idempotencyKey !== null) {
            $existing = $this->payments->findByIdempotencyKey($input->idempotencyKey);
            if ($existing !== null) {
                return new OpenedPayment($existing, null); // idempotent replay
            }
        }

        $decision = $this->fraud->assess($input->payerUserId, $input->amount, $ipAddress);
        if (! $decision->allow) {
            throw new PaymentsInvalidState($decision->reason ?? 'Payment declined by fraud checks.');
        }

        $gateway = $input->provider !== null
            ? $this->gateways->for($input->provider)
            : $this->gateways->default();

        $now = new DateTimeImmutable();
        $payment = Payment::initiate(
            id: $this->payments->nextIdentity(),
            reference: $this->payments->nextReference(),
            orderId: $input->orderId,
            payerUserId: $input->payerUserId,
            amount: $input->amount,
            provider: $gateway->provider(),
            methodType: $input->methodType,
            idempotencyKey: $input->idempotencyKey ?? (string) Str::uuid(),
            splits: $input->splits,
            now: $now,
        );

        $result = $gateway->initialize(new GatewayCharge(
            reference: $payment->reference(),
            amount: $input->amount,
            customerEmail: $input->customerEmail,
            methodType: $input->methodType,
            metadata: ['order_id' => $input->orderId],
        ));

        $reference = new ProviderReference($gateway->provider(), $result->providerReference);
        if ($result->status === 'succeeded') {
            $payment->markProcessing($reference, $now);
            $this->capture($payment, $now);
        } elseif ($result->success) {
            $payment->markProcessing($reference, $now);
        } else {
            $payment->markFailed($result->message ?? 'Initialization failed.', $now);
        }

        $this->persist($payment);

        return new OpenedPayment($payment, $result->authorizationUrl);
    }

    /** Verify a payment with the provider and capture it if the provider confirms. */
    public function verify(string $paymentId): Payment
    {
        $payment = $this->getById($paymentId);
        if ($payment->status()->isCaptured() || $payment->status()->isTerminal()) {
            return $payment;
        }
        $ref = $payment->providerReference();
        if ($ref === null) {
            throw new PaymentsInvalidState('Payment has no provider reference to verify.');
        }
        $result = $this->gateways->for($payment->provider())->verify($ref->reference);
        $now = new DateTimeImmutable();
        if ($result->status === 'succeeded') {
            $this->capture($payment, $now);
        } elseif ($result->status === 'failed') {
            $payment->markFailed($result->message ?? 'Verification failed.', $now);
        }
        $this->persist($payment);

        return $payment;
    }

    /** Apply a normalised webhook outcome to a payment (used by WebhookService). */
    public function applyOutcome(string $provider, string $providerReference, string $status): ?Payment
    {
        $payment = $this->payments->findByProviderReference($provider, $providerReference);
        if ($payment === null) {
            return null;
        }
        $now = new DateTimeImmutable();
        if ($status === 'succeeded') {
            $this->capture($payment, $now);
        } elseif ($status === 'failed') {
            $payment->markFailed('Provider reported failure.', $now);
        }
        $this->persist($payment);

        return $payment;
    }

    public function cancel(string $paymentId, string $actorUserId, bool $actorIsAdmin): Payment
    {
        $payment = $this->getById($paymentId);
        $this->assertActor($payment, $actorUserId, $actorIsAdmin);
        $payment->cancel(new DateTimeImmutable());
        $this->persist($payment);

        return $payment;
    }

    public function getById(string $paymentId): Payment
    {
        return $this->payments->findById($paymentId) ?? throw PaymentsNotFound::of('payment', $paymentId);
    }

    public function forPayer(string $userId, int $page, int $perPage): Paginated
    {
        return $this->payments->forPayer($userId, $page, $perPage);
    }

    public function all(?PaymentStatus $status, int $page, int $perPage): Paginated
    {
        return $this->payments->all($status, $page, $perPage);
    }

    /** Mark captured and post the ledger (commission/fees/escrow). Idempotent. */
    private function capture(Payment $payment, DateTimeImmutable $now): void
    {
        if ($payment->status()->isCaptured()) {
            return;
        }
        $payment->markSucceeded($now);

        $gross = $payment->amount();
        $commission = $this->commission->commissionOn($gross);
        $fees = $this->commission->feeOn($gross);
        $net = $gross->subtract($commission)->subtract($fees);
        if ($net->minorUnits < 0) {
            $net = new Money(0, $gross->currency);
        }
        $this->ledger->recordCapture($payment->id(), $payment->reference(), $gross, $commission, $fees, $net);
        $this->notifier->paymentSucceeded($payment);
    }

    private function persist(Payment $payment): void
    {
        $this->payments->save($payment);
        foreach ($payment->releaseEvents() as $event) {
            $this->events->publish($event);
        }
        if ($payment->status() === PaymentStatus::Failed) {
            $this->notifier->paymentFailed($payment);
        }
    }

    private function assertActor(Payment $payment, string $actorUserId, bool $actorIsAdmin): void
    {
        if (! $actorIsAdmin && ! $payment->isForPayer($actorUserId)) {
            throw new \EruoFood\Payments\Domain\Exception\PaymentsNotAuthorized();
        }
    }
}
