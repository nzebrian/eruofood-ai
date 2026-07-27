<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller\Admin;

use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\StoreService;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;

/** Admin store moderation: verify / suspend. */
final readonly class StoreAdminController
{
    use RespondsWithData;

    public function __construct(
        private StoreService $stores,
        private CommercePresenter $presenter,
    ) {
    }

    public function verify(string $id): JsonResponse
    {
        return $this->data($this->presenter->store($this->stores->verify($id)));
    }

    public function suspend(string $id): JsonResponse
    {
        return $this->data($this->presenter->store($this->stores->suspend($id)));
    }
}
