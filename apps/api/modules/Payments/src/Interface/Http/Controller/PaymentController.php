<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller;

use EruoFood\Payments\Application\Input\InitiatePaymentInput;
use EruoFood\Payments\Application\Service\PaymentService;
use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Domain\Payment\Payment;
use EruoFood\Payments\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Payments\Interface\Http\Request\InitiatePaymentRequest;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Interface\Http\Concerns\UsesIdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer payments: initiate at checkout, verify, view and cancel. */
final readonly class PaymentController
{
    use RespondsWithData;
    use ResolvesAuthUser;
    use UsesIdempotencyKey;

    public function __construct(
        private PaymentService $payments,
        private PaymentsPresenter $presenter,
        private IdempotencyStore $idempotency,
        private string $currency,
    ) {
    }

    /**
     * Open a payment.
     *
     * ## Why this needed an idempotency key
     *
     * The coverage audit found this endpoint uncovered while checkout, wallet
     * top-up, transfer, refund and dispatch acceptance were all guarded. It is
     * the one that opens a charge at the provider — so a client that timed out
     * and retried got a *second* payment intent against the same order, and the
     * customer could complete both.
     *
     * The key is optional, exactly as it is on refunds: with one, a retry
     * replays the original intent (200); without one, the previous behaviour is
     * unchanged. That keeps existing callers working while giving a mobile
     * client on a bad connection a way to be safe.
     */
    public function store(InitiatePaymentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['payer_user_id'] = $this->currentUserId($request);
        $ip = (string) $request->ip();

        $result = $this->idempotency->execute(
            'payments.initiate',
            $this->idempotencyKey($request),
            // The IP is deliberately excluded from the fingerprint: a client
            // that reconnects on a different network is the *same* request, and
            // fingerprinting the address would reject its retry as a reused key
            // at exactly the moment the guard is most needed.
            $this->requestFingerprint($data),
            function () use ($data, $ip): array {
                $opened = $this->payments->open(
                    InitiatePaymentInput::fromArray($data, $this->currency),
                    $ip,
                );

                return $this->presenter->paymentIntent($opened->payment, $opened->authorizationUrl);
            },
        );

        return $this->data($result->value, $result->replayed ? 200 : 201);
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        $payment = $this->payments->verify($id);

        return $this->data($this->presenter->payment($payment));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $payment = $this->payments->getById($id);
        if (! $this->actorIsAdmin($request) && ! $payment->isForPayer($this->currentUserId($request))) {
            throw new \EruoFood\Payments\Domain\Exception\PaymentsNotAuthorized();
        }

        return $this->data($this->presenter->payment($payment));
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->payments->forPayer(
            $this->currentUserId($request),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (Payment $p): array => $this->presenter->payment($p));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $payment = $this->payments->cancel($id, $this->currentUserId($request), $this->actorIsAdmin($request));

        return $this->data($this->presenter->payment($payment));
    }
}
