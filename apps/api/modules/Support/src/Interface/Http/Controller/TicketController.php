<?php

declare(strict_types=1);

namespace EruoFood\Support\Interface\Http\Controller;

use EruoFood\Support\Application\Service\CsatService;
use EruoFood\Support\Application\Service\SupportPresenter;
use EruoFood\Support\Application\Service\SupportService;
use EruoFood\Support\Domain\Enum\TicketChannel;
use EruoFood\Support\Domain\Enum\TicketPriority;
use EruoFood\Support\Domain\Ticket\TicketQuery;
use EruoFood\Support\Domain\ValueObject\Attachment;
use EruoFood\Support\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Support\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer support portal: raise tickets, view and reply to your own, rate resolutions. */
final class TicketController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly SupportService $support,
        private readonly CsatService $csat,
        private readonly SupportPresenter $presenter,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'channel' => ['nullable', 'in:web,email,chat,in_app,phone,whatsapp,voice'],
            'related_order_id' => ['nullable', 'uuid'],
            'attachments' => ['nullable', 'array'],
        ]);

        $ticket = $this->support->open(
            $userId,
            $data['subject'],
            $data['category'],
            TicketChannel::from($data['channel'] ?? 'web'),
            TicketPriority::from($data['priority'] ?? 'normal'),
            $data['body'],
            $this->attachments($request),
            $data['related_order_id'] ?? null,
        );

        return $this->data($this->presenter->ticket($ticket, false), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);
        $query = new TicketQuery(
            requesterId: $userId,
            page: (int) $request->query('page', '1'),
            perPage: (int) $request->query('per_page', '20'),
        );

        return $this->paginated($this->support->queue($query), fn ($t): array => $this->presenter->ticketSummary($t));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $ticket = $this->support->getForCustomer($id, $this->currentUserId($request));

        return $this->data($this->presenter->ticket($ticket, false));
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $userId = $this->currentUserId($request);
        $data = $request->validate(['body' => ['required', 'string'], 'attachments' => ['nullable', 'array']]);
        $ticket = $this->support->customerReply($id, $userId, $data['body'], $this->attachments($request));

        return $this->data($this->presenter->ticket($ticket, false));
    }

    public function csat(Request $request, string $id): JsonResponse
    {
        $userId = $this->currentUserId($request);
        $data = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $response = $this->csat->submit($id, $userId, (int) $data['score'], $data['comment'] ?? null);

        return $this->data($this->presenter->csat($response), 201);
    }

    /**
     * @return list<Attachment>
     */
    private function attachments(Request $request): array
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = (array) $request->input('attachments', []);

        return array_map(static fn (array $a): Attachment => Attachment::fromArray($a), $raw);
    }
}
