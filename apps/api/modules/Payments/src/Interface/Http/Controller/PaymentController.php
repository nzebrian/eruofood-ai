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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer payments: initiate at checkout, verify, view and cancel. */
final readonly class PaymentController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private PaymentService $payments,
        private PaymentsPresenter $presenter,
        private string $currency,
    ) {
    }

    public function store(InitiatePaymentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['payer_user_id'] = $this->currentUserId($request);

        $opened = $this->payments->open(
            InitiatePaymentInput::fromArray($data, $this->currency),
            (string) $request->ip(),
        );

        return $this->data($this->presenter->paymentIntent($opened->payment, $opened->authorizationUrl), 201);
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
