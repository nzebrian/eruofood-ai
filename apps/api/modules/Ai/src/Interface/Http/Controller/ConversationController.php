<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Controller;

use EruoFood\Ai\Application\Service\AiPresenter;
use EruoFood\Ai\Application\Service\ConversationService;
use EruoFood\Ai\Domain\Conversation\Conversation;
use EruoFood\Ai\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Ai\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** AI chat history: list, read, rename and delete the caller's conversations. */
final readonly class ConversationController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private ConversationService $conversations,
        private AiPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->conversations->list(
            $this->currentUserId($request),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (Conversation $c): array => $this->presenter->conversationSummary($c));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $conversation = $this->conversations->get($this->currentUserId($request), $id);

        return $this->data($this->presenter->conversation($conversation));
    }

    public function rename(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['title' => ['required', 'string', 'min:1', 'max:160']]);
        $conversation = $this->conversations->rename($this->currentUserId($request), $id, (string) $validated['title']);

        return $this->data($this->presenter->conversationSummary($conversation));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->conversations->delete($this->currentUserId($request), $id);

        return new JsonResponse(null, 204);
    }
}
