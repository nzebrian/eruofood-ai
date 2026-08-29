<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller;

use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Application\Service\SubscriptionService;
use EruoFood\Payments\Domain\Subscription\Subscription;
use EruoFood\Payments\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Interface\Http\Concerns\UsesIdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Recurring subscription billing. */
final readonly class SubscriptionController
{
    use RespondsWithData;
    use ResolvesAuthUser;
    use UsesIdempotencyKey;

    public function __construct(
        private SubscriptionService $subscriptions,
        private PaymentsPresenter $presenter,
        private IdempotencyStore $idempotency,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $subs = $this->subscriptions->forUser($this->currentUserId($request));

        return $this->data(array_map(fn (Subscription $s): array => $this->presenter->subscription($s), $subs));
    }

    /**
     * Start a subscription.
     *
     * ## Why this needed an idempotency key
     *
     * A subscription is a standing instruction to charge someone every week or
     * month. A client that posts, times out, and posts again used to get *two*
     * of them — and nothing downstream would ever notice, because two identical
     * subscriptions for one user are indistinguishable from a customer who
     * genuinely wanted two. The billing sweep would then charge twice, for ever.
     *
     * Unlike payments, refunds and wallet moves — where the header is optional
     * and its absence merely forfeits the guard — the key is **required** here.
     * A duplicate payment is one extra charge the customer can see and dispute;
     * a duplicate subscription is one extra charge every period, and no later
     * reconciliation can distinguish it from a customer who wanted two. A caller
     * that omits the header is told so (422) rather than quietly served the
     * unguarded path.
     *
     * ## Where the guarantee comes from
     *
     * Not from a lookup. The claim is an INSERT against
     * `unique(scope, idempotency_key)`, so two simultaneous retries are
     * arbitrated by the database — one creates the subscription, the other is
     * told the work is in flight (409) and creates nothing.
     *
     * The key is bound to the caller before it is claimed, so one user's key
     * cannot reach another user's record and two users may use the same key
     * value independently. The principal is *also* in the fingerprint: if the
     * derivation were ever weakened, a cross-user replay would still be refused
     * as a reused key rather than answered with someone else's subscription.
     */
    public function store(Request $request): JsonResponse
    {
        // Validation runs before the claim, so a rejected request leaves no
        // record behind and the key stays usable.
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:80'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'interval' => ['required', 'in:weekly,monthly'],
        ]);
        $userId = $this->currentUserId($request);

        $result = $this->idempotency->execute(
            'payments.subscription',
            $this->requirePrincipalScopedIdempotencyKey($request, $userId),
            $this->requestFingerprint($data + ['actor' => $userId]),
            function () use ($data, $userId): array {
                $sub = $this->subscriptions->start(
                    $userId,
                    (string) $data['plan'],
                    (int) $data['amount_minor'],
                    (string) $data['interval'],
                );

                return $this->presenter->subscription($sub);
            },
            principalId: $userId,
        );

        return $this->data($result->value, $result->replayed ? 200 : 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $sub = $this->subscriptions->cancel($id, $this->currentUserId($request));

        return $this->data($this->presenter->subscription($sub));
    }
}
