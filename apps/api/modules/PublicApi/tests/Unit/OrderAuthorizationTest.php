<?php

declare(strict_types=1);

use EruoFood\PublicApi\Application\Service\OrderApiService;
use EruoFood\PublicApi\Domain\Authorization\Principal;
use EruoFood\PublicApi\Domain\Exception\PublicApiForbidden;
use EruoFood\PublicApi\Domain\Order\OrderDraft;
use EruoFood\PublicApi\Domain\Order\OrderPort;
use EruoFood\PublicApi\Domain\Order\OrderResource;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use EruoFood\Shared\Domain\Paginated;

/**
 * Object-level authorization (BOLA/IDOR) for the public Orders API. Proves that
 * the customer id passed to the Order domain is always the authenticated
 * principal's subject — never a client-supplied value — and that an
 * application-level credential (no subject) cannot reach customer orders.
 */
function recordingOrderPort(): OrderPort
{
    return new class () implements OrderPort {
        public array $seenUserIds = [];

        public function listForCustomer(string $userId, int $page, int $perPage): Paginated
        {
            $this->seenUserIds[] = $userId;

            return new Paginated([], 0, $page, $perPage);
        }

        public function getForCustomer(string $orderId, string $userId): OrderResource
        {
            $this->seenUserIds[] = $userId;

            return new OrderResource($orderId, 'REF', 'placed', $userId, 0, 'NGN', false, null, [], '2027-01-01T00:00:00+00:00');
        }

        public function create(string $userId, OrderDraft $draft): OrderResource
        {
            $this->seenUserIds[] = $userId;

            return new OrderResource('o1', 'REF', 'placed', $userId, 0, 'NGN', false, null, [], '2027-01-01T00:00:00+00:00');
        }

        public function cancel(string $orderId, string $userId): OrderResource
        {
            $this->seenUserIds[] = $userId;

            return new OrderResource($orderId, 'REF', 'cancelled', $userId, 0, 'NGN', false, null, [], '2027-01-01T00:00:00+00:00');
        }
    };
}

function customerPrincipal(string $userId): Principal
{
    return new Principal('app-1', 'dev-1', new ScopeSet(['orders:read', 'orders:write']), $userId, 'api_key');
}

function appLevelPrincipal(): Principal
{
    return new Principal('app-1', 'dev-1', new ScopeSet(['orders:read', 'orders:write']), null, 'api_key');
}

it('always passes the principal subject to the Order domain (never a client id)', function (): void {
    $port = recordingOrderPort();
    $service = new OrderApiService($port);
    $principal = customerPrincipal('user-42');

    $service->list($principal, 1, 20);
    $service->get($principal, 'order-owned-by-someone-else');
    $service->create($principal, new OrderDraft());
    $service->cancel($principal, 'another-users-order');

    // Every downstream call used the authenticated subject — never the URL id.
    expect($port->seenUserIds)->toBe(['user-42', 'user-42', 'user-42', 'user-42']);
});

it('refuses an application-level credential for customer orders (BOLA)', function (): void {
    $service = new OrderApiService(recordingOrderPort());
    $principal = appLevelPrincipal();

    expect(fn () => $service->list($principal, 1, 20))->toThrow(PublicApiForbidden::class);
    expect(fn () => $service->get($principal, 'o1'))->toThrow(PublicApiForbidden::class);
    expect(fn () => $service->create($principal, new OrderDraft()))->toThrow(PublicApiForbidden::class);
    expect(fn () => $service->cancel($principal, 'o1'))->toThrow(PublicApiForbidden::class);
});
