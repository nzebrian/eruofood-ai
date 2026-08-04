<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Interface\Http\Controller;

use EruoFood\Notifications\Application\Service\NotificationService;
use EruoFood\Notifications\Application\Service\NotificationsPresenter;
use EruoFood\Notifications\Domain\Notification\Notification;
use EruoFood\Notifications\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Notifications\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The in-app notification centre. */
final readonly class NotificationController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private NotificationService $notifications,
        private NotificationsPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->notifications->centre(
            $this->currentUserId($request),
            $request->boolean('unread'),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (Notification $n): array => $this->presenter->notification($n));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->data(['unread' => $this->notifications->unreadCount($this->currentUserId($request))]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->notifications->markRead($id, $this->currentUserId($request));

        return $this->data($this->presenter->notification($notification));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->notifications->markAllRead($this->currentUserId($request));

        return new JsonResponse(null, 204);
    }
}
