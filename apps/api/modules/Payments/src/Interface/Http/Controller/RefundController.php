<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller;

use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Application\Service\RefundService;
use EruoFood\Payments\Domain\Payment\Refund;
use EruoFood\Payments\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Interface\Http\Concerns\UsesIdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer-initiated refunds (admins can also refund via the admin routes). */
final readonly class RefundController
{
    use RespondsWithData;
    use ResolvesAuthUser;
    use UsesIdempotencyKey;

    public function __construct(
        private RefundService $refunds,
        private PaymentsPresenter $presenter,
        private IdempotencyStore $idempotency,
    ) {
    }

    /**
     * Request a refund.
     *
     * A refund sends real money, so a client that retries after a timeout must
     * not trigger a second one. With an `Idempotency-Key` header the retry
     * replays the original refund (200); without one the old behaviour applies.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_id' => ['required', 'uuid'],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $userId = $this->currentUserId($request);
        $isAdmin = $this->actorIsAdmin($request);

        $result = $this->idempotency->execute(
            'payments.refund',
            $this->idempotencyKey($request),
            $this->requestFingerprint($data + ['actor' => $userId]),
            function () use ($data, $userId, $isAdmin): array {
                $refund = $this->refunds->request(
                    (string) $data['payment_id'],
                    isset($data['amount_minor']) ? (int) $data['amount_minor'] : null,
                    (string) $data['reason'],
                    $userId,
                    $isAdmin,
                );

                return $this->presenter->refund($refund);
            },
        );

        return $this->data($result->value, $result->replayed ? 200 : 201);
    }

    public function forPayment(Request $request, string $paymentId): JsonResponse
    {
        $refunds = $this->refunds->forPayment($paymentId);

        return $this->data(array_map(fn (Refund $r): array => $this->presenter->refund($r), $refunds));
    }
}
