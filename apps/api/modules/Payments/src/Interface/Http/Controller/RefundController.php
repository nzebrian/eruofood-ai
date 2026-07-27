<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller;

use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Application\Service\RefundService;
use EruoFood\Payments\Domain\Payment\Refund;
use EruoFood\Payments\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer-initiated refunds (admins can also refund via the admin routes). */
final readonly class RefundController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private RefundService $refunds,
        private PaymentsPresenter $presenter,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_id' => ['required', 'uuid'],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $refund = $this->refunds->request(
            (string) $data['payment_id'],
            isset($data['amount_minor']) ? (int) $data['amount_minor'] : null,
            (string) $data['reason'],
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
        );

        return $this->data($this->presenter->refund($refund), 201);
    }

    public function forPayment(Request $request, string $paymentId): JsonResponse
    {
        $refunds = $this->refunds->forPayment($paymentId);

        return $this->data(array_map(fn (Refund $r): array => $this->presenter->refund($r), $refunds));
    }
}
