<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller\Admin;

use DateTimeImmutable;
use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\CouponService;
use EruoFood\Commerce\Domain\Enum\CouponType;
use EruoFood\Commerce\Domain\Promotion\Coupon;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin coupon management. */
final readonly class CouponAdminController
{
    use RespondsWithData;

    public function __construct(
        private CouponService $coupons,
        private CommercePresenter $presenter,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->data(array_map(fn (Coupon $c): array => $this->presenter->coupon($c), $this->coupons->list()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'type' => ['required', 'in:percentage,fixed,free_shipping'],
            'value' => ['required', 'integer', 'min:0'],
            'min_spend_minor' => ['nullable', 'integer', 'min:0'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $coupon = $this->coupons->create(
            (string) $data['code'],
            CouponType::from((string) $data['type']),
            (int) $data['value'],
            (int) ($data['min_spend_minor'] ?? 0),
            isset($data['max_redemptions']) ? (int) $data['max_redemptions'] : null,
            isset($data['expires_at']) ? new DateTimeImmutable((string) $data['expires_at']) : null,
        );

        return $this->data($this->presenter->coupon($coupon), 201);
    }

    public function deactivate(string $id): JsonResponse
    {
        return $this->data($this->presenter->coupon($this->coupons->deactivate($id)));
    }
}
