<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller;

use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Application\Service\SubscriptionService;
use EruoFood\Payments\Domain\Subscription\Subscription;
use EruoFood\Payments\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Recurring subscription billing. */
final readonly class SubscriptionController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private SubscriptionService $subscriptions,
        private PaymentsPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $subs = $this->subscriptions->forUser($this->currentUserId($request));

        return $this->data(array_map(fn (Subscription $s): array => $this->presenter->subscription($s), $subs));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:80'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'interval' => ['required', 'in:weekly,monthly'],
        ]);
        $sub = $this->subscriptions->start(
            $this->currentUserId($request),
            (string) $data['plan'],
            (int) $data['amount_minor'],
            (string) $data['interval'],
        );

        return $this->data($this->presenter->subscription($sub), 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $sub = $this->subscriptions->cancel($id, $this->currentUserId($request));

        return $this->data($this->presenter->subscription($sub));
    }
}
