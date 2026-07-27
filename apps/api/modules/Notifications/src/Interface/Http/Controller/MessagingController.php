<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Interface\Http\Controller;

use EruoFood\Notifications\Application\Service\MessagingService;
use EruoFood\Notifications\Application\Service\NotificationsPresenter;
use EruoFood\Notifications\Domain\Enum\ConversationType;
use EruoFood\Notifications\Domain\Enum\MessageType;
use EruoFood\Notifications\Domain\Messaging\Conversation;
use EruoFood\Notifications\Domain\Messaging\Message;
use EruoFood\Notifications\Domain\ValueObject\Attachment;
use EruoFood\Notifications\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Notifications\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Real-time chat: conversations, messages, read receipts and typing indicators. */
final readonly class MessagingController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private MessagingService $messaging,
        private NotificationsPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $conversations = $this->messaging->inbox($this->currentUserId($request));

        return $this->data(array_map(fn (Conversation $c): array => $this->presenter->conversation($c), $conversations));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:customer_restaurant,customer_vendor,customer_rider,admin_user,group'],
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['uuid'],
            'subject' => ['nullable', 'string', 'max:150'],
            'context_ref' => ['nullable', 'string', 'max:100'],
        ]);
        $conversation = $this->messaging->startConversation(
            $this->currentUserId($request),
            ConversationType::from((string) $data['type']),
            array_map('strval', $data['participant_ids']),
            isset($data['subject']) ? (string) $data['subject'] : null,
            isset($data['context_ref']) ? (string) $data['context_ref'] : null,
        );

        return $this->data($this->presenter->conversation($conversation), 201);
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        $page = $this->messaging->messages(
            $id,
            $this->currentUserId($request),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 30),
        );

        return $this->paginated($page, fn (Message $m): array => $this->presenter->message($m));
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:text,file,voice'],
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:4000'],
            'attachments' => ['array'],
            'attachments.*.url' => ['required_with:attachments', 'string', 'max:2048'],
            'attachments.*.name' => ['nullable', 'string', 'max:200'],
            'attachments.*.mime_type' => ['nullable', 'string', 'max:100'],
            'attachments.*.size_bytes' => ['nullable', 'integer', 'min:0'],
        ]);
        $attachments = array_map(
            static fn (array $a): Attachment => Attachment::fromArray($a),
            $data['attachments'] ?? [],
        );
        $message = $this->messaging->send(
            $id,
            $this->currentUserId($request),
            MessageType::from((string) ($data['type'] ?? 'text')),
            (string) ($data['body'] ?? ''),
            array_values($attachments),
        );

        return $this->data($this->presenter->message($message), 201);
    }

    public function markRead(Request $request, string $messageId): JsonResponse
    {
        $message = $this->messaging->markRead($messageId, $this->currentUserId($request));

        return $this->data($this->presenter->message($message));
    }

    public function typing(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['typing' => ['required', 'boolean']]);
        $this->messaging->typing($id, $this->currentUserId($request), (bool) $data['typing']);

        return new JsonResponse(null, 204);
    }
}
