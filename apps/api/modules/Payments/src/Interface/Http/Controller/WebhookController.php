<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller;

use EruoFood\Payments\Application\Service\WebhookService;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public webhook endpoint (no auth — the provider signs the payload). Each
 * provider posts to /payments/webhooks/{provider}; the service verifies the
 * signature, dedups on the event id, and applies the outcome exactly-once.
 */
final readonly class WebhookController
{
    public function __construct(private WebhookService $webhooks)
    {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        $signature = (string) ($request->header('X-Paystack-Signature')
            ?? $request->header('verif-hash')
            ?? $request->header('X-Moniepoint-Signature')
            ?? $request->header('Stripe-Signature')
            ?? $request->header('Paypal-Transmission-Sig')
            ?? '');

        try {
            $applied = $this->webhooks->handle($provider, $request->getContent(), $signature);
        } catch (PaymentsInvalidState $e) {
            return new JsonResponse(['error' => ['code' => 'PAYMENTS_INVALID_STATE', 'message' => $e->getMessage()]], 400);
        }

        return new JsonResponse(['received' => true, 'applied' => $applied]);
    }
}
