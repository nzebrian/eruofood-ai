<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Interface\Http\Controller\Admin;

use DateTimeImmutable;
use EruoFood\Notifications\Application\Service\BroadcastService;
use EruoFood\Notifications\Application\Service\NotificationsPresenter;
use EruoFood\Notifications\Domain\Broadcast\Broadcast;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin broadcast messaging & campaign manager (RBAC). */
final readonly class BroadcastAdminController
{
    use RespondsWithData;

    public function __construct(
        private BroadcastService $broadcasts,
        private NotificationsPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->broadcasts->all((int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Broadcast $b): array => $this->presenter->broadcast($b));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
            'category' => ['nullable', 'in:promotional,admin,ai,nutrition'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['in:email,sms,push,in_app,whatsapp,telegram'],
            'segment' => ['required', 'string', 'max:200'],
            'scheduled_for' => ['nullable', 'date'],
        ]);
        $channels = array_map(static fn (string $c): NotificationChannel => NotificationChannel::from($c), $data['channels']);
        $broadcast = $this->broadcasts->create(
            (string) $data['title'],
            (string) $data['body'],
            NotificationCategory::from((string) ($data['category'] ?? 'promotional')),
            array_values($channels),
            (string) $data['segment'],
            isset($data['scheduled_for']) ? new DateTimeImmutable((string) $data['scheduled_for']) : null,
        );

        return $this->data($this->presenter->broadcast($broadcast), 201);
    }

    public function send(string $id): JsonResponse
    {
        return $this->data($this->presenter->broadcast($this->broadcasts->send($id)));
    }
}
