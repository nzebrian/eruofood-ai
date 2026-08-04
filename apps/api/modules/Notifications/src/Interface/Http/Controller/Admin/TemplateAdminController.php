<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Interface\Http\Controller\Admin;

use EruoFood\Notifications\Application\Service\NotificationsPresenter;
use EruoFood\Notifications\Application\Service\TemplateService;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Template\NotificationTemplate;
use EruoFood\Notifications\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Notification template management (RBAC). */
final readonly class TemplateAdminController
{
    use RespondsWithData;

    public function __construct(
        private TemplateService $templates,
        private NotificationsPresenter $presenter,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->data(array_map(fn (NotificationTemplate $t): array => $this->presenter->template($t), $this->templates->all()));
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'channel' => ['required', 'in:email,sms,push,in_app,whatsapp,telegram'],
            'locale' => ['nullable', 'string', 'max:8'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:4000'],
        ]);
        $template = $this->templates->upsert(
            (string) $data['key'],
            NotificationChannel::from((string) $data['channel']),
            (string) ($data['locale'] ?? 'en'),
            (string) $data['subject'],
            (string) $data['body'],
        );

        return $this->data($this->presenter->template($template), 201);
    }
}
