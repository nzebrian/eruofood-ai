<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Interface\Http\Controller\Admin;

use EruoFood\Notifications\Application\Service\DeliveryReportService;
use EruoFood\Notifications\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;

/** Admin delivery reports / analytics dashboard (RBAC). */
final readonly class NotificationsAdminController
{
    use RespondsWithData;

    public function __construct(private DeliveryReportService $reports)
    {
    }

    public function report(): JsonResponse
    {
        return $this->data($this->reports->report()->toArray());
    }
}
