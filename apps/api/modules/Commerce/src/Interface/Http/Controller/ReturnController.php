<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\ReturnService;
use EruoFood\Commerce\Domain\Order\ReturnRequest;
use EruoFood\Commerce\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer returns & refunds. */
final readonly class ReturnController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private ReturnService $returns,
        private CommercePresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->returns->forCustomer(
            $this->currentUserId($request),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (ReturnRequest $r): array => $this->presenter->returnRequest($r));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $return = $this->returns->request(
            (string) $data['order_id'],
            $this->currentUserId($request),
            (string) $data['reason'],
        );

        return $this->data($this->presenter->returnRequest($return), 201);
    }
}
