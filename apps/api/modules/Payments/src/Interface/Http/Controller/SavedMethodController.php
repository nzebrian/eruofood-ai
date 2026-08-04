<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller;

use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Application\Service\SavedMethodService;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Method\SavedPaymentMethod;
use EruoFood\Payments\Domain\ValueObject\CardFingerprint;
use EruoFood\Payments\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tokenised saved payment methods (PCI-safe — display data + provider token only). */
final readonly class SavedMethodController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private SavedMethodService $methods,
        private PaymentsPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $methods = $this->methods->forUser($this->currentUserId($request));

        return $this->data(array_map(fn (SavedPaymentMethod $m): array => $this->presenter->savedMethod($m), $methods));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:paystack,flutterwave,moniepoint,stripe,paypal'],
            'token' => ['required', 'string', 'max:200'],
            'brand' => ['required', 'string', 'max:40'],
            'last4' => ['required', 'string', 'size:4'],
            'expiry_month' => ['required', 'integer', 'min:1', 'max:12'],
            'expiry_year' => ['required', 'integer', 'min:2024', 'max:2100'],
            'default' => ['boolean'],
        ]);
        $method = $this->methods->save(
            $this->currentUserId($request),
            PaymentProvider::from((string) $data['provider']),
            CardFingerprint::fromArray($data),
            (bool) ($data['default'] ?? false),
        );

        return $this->data($this->presenter->savedMethod($method), 201);
    }

    public function makeDefault(Request $request, string $id): JsonResponse
    {
        $method = $this->methods->makeDefault($id, $this->currentUserId($request));

        return $this->data($this->presenter->savedMethod($method));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->methods->delete($id, $this->currentUserId($request));

        return new JsonResponse(null, 204);
    }
}
