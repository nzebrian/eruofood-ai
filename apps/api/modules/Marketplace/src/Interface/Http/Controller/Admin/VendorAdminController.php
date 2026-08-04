<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller\Admin;

use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Application\Service\VendorService;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin vendor verification & moderation (RBAC: admin). */
final readonly class VendorAdminController
{
    use RespondsWithData;

    public function __construct(
        private VendorService $vendors,
        private MarketplacePresenter $presenter,
    ) {
    }

    public function verify(string $id): JsonResponse
    {
        return $this->data($this->presenter->vendor($this->vendors->verify($id)));
    }

    public function reject(string $id): JsonResponse
    {
        return $this->data($this->presenter->vendor($this->vendors->reject($id)));
    }

    public function suspend(string $id): JsonResponse
    {
        return $this->data($this->presenter->vendor($this->vendors->suspend($id)));
    }

    public function feature(Request $request, string $id): JsonResponse
    {
        $featured = $request->boolean('featured', true);

        return $this->data($this->presenter->vendor($this->vendors->setFeatured($id, $featured)));
    }
}
