<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Public;

use EruoFood\PublicApi\Application\Service\OrderApiService;
use EruoFood\PublicApi\Application\Transformer\OrderTransformer;
use EruoFood\PublicApi\Domain\Order\OrderDraft;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use EruoFood\PublicApi\Interface\Http\Concerns\ResolvesPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public Orders API. Reads require `orders:read`, writes `orders:write`, and
 * every operation is bound to the authenticated principal's subject user — a
 * caller can only ever see or change its own customer's orders (BOLA-safe). The
 * Order domain is never bypassed; creation goes through checkout.
 */
final class OrdersController
{
    use ResolvesPrincipal;
    use RespondsWithEnvelope;

    public function __construct(
        private readonly OrderApiService $orders,
        private readonly OrderTransformer $transformer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->resourceQuery($request);
        $page = $this->orders->list($this->principal($request), $query->page, $query->perPage);

        return $this->collection($page, fn ($o): array => $this->transformer->order($o));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->item($this->transformer->order($this->orders->get($this->principal($request), $id)));
    }

    public function status(Request $request, string $id): JsonResponse
    {
        return $this->item($this->transformer->status($this->orders->get($this->principal($request), $id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pickup' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
            'scheduled_for' => ['nullable', 'date'],
            'shipping_address' => ['nullable', 'array'],
        ]);
        $draft = new OrderDraft(
            (bool) ($data['pickup'] ?? false),
            $data['note'] ?? null,
            $data['scheduled_for'] ?? null,
            $data['shipping_address'] ?? null,
        );

        return $this->item($this->transformer->order($this->orders->create($this->principal($request), $draft)), [], 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->item($this->transformer->order($this->orders->cancel($this->principal($request), $id)));
    }
}
