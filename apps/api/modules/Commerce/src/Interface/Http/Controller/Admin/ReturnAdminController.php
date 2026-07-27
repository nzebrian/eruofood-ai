<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller\Admin;

use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\ReturnService;
use EruoFood\Commerce\Domain\Enum\ReturnStatus;
use EruoFood\Commerce\Domain\Order\ReturnRequest;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin returns & refunds resolution. */
final readonly class ReturnAdminController
{
    use RespondsWithData;

    public function __construct(
        private ReturnService $returns,
        private CommercePresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->returns->all((int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (ReturnRequest $r): array => $this->presenter->returnRequest($r));
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected,refunded'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $return = $this->returns->resolve(
            $id,
            ReturnStatus::from((string) $data['status']),
            isset($data['note']) ? (string) $data['note'] : null,
        );

        return $this->data($this->presenter->returnRequest($return));
    }
}
