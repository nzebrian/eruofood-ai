<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use EruoFood\Notifications\Domain\Broadcast\Broadcast;
use EruoFood\Notifications\Domain\Messaging\Conversation;
use EruoFood\Notifications\Domain\Messaging\Message;
use EruoFood\Notifications\Domain\Notification\Notification;
use EruoFood\Notifications\Domain\Preference\NotificationPreference;
use EruoFood\Notifications\Domain\Template\NotificationTemplate;
use EruoFood\Notifications\Domain\ValueObject\Attachment;

/** Maps Notifications aggregates to API-shaped arrays. */
final readonly class NotificationsPresenter
{
    /** @return array<string, mixed> */
    public function notification(Notification $n): array
    {
        return [
            'id' => $n->id(),
            'category' => $n->category()->value,
            'channel' => $n->channel()->value,
            'template_key' => $n->templateKey(),
            'subject' => $n->content()->subject,
            'body' => $n->content()->body,
            'priority' => $n->priority()->value,
            'status' => $n->status()->value,
            'read' => $n->isRead(),
            'read_at' => $n->readAt()?->format(DATE_ATOM),
            'scheduled_for' => $n->scheduledFor()?->format(DATE_ATOM),
            'created_at' => $n->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function preference(NotificationPreference $p): array
    {
        return [
            'user_id' => $p->userId(),
            'channels_by_category' => $p->channelsByCategory(),
            'quiet_hours' => $p->quietHours()->toArray(),
            'language' => $p->language(),
            'max_per_day' => $p->maxPerDay(),
            'marketing_opt_in' => $p->marketingOptIn(),
            'marketing_opt_in_at' => $p->marketingOptInAt()?->format(DATE_ATOM),
            // The token itself is never returned: it is a bearer secret that
            // belongs in an email link, not in an API response that a browser
            // extension or a shared screenshot could carry away.
        ];
    }

    /** @return array<string, mixed> */
    public function conversation(Conversation $c): array
    {
        return [
            'id' => $c->id(),
            'type' => $c->type()->value,
            'participant_ids' => $c->participantIds(),
            'subject' => $c->subject(),
            'context_ref' => $c->contextRef(),
            'last_message_at' => $c->lastMessageAt()->format(DATE_ATOM),
            'created_at' => $c->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function message(Message $m): array
    {
        return [
            'id' => $m->id(),
            'conversation_id' => $m->conversationId(),
            'sender_id' => $m->senderId(),
            'type' => $m->type()->value,
            'body' => $m->body(),
            'attachments' => array_map(static fn (Attachment $a): array => $a->toArray(), $m->attachments()),
            'read_by' => $m->readBy(),
            'created_at' => $m->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function broadcast(Broadcast $b): array
    {
        return [
            'id' => $b->id(),
            'title' => $b->title(),
            'body' => $b->body(),
            'category' => $b->category()->value,
            'channels' => $b->channels(),
            'segment' => $b->segment(),
            'scheduled_for' => $b->scheduledFor()?->format(DATE_ATOM),
            'sent' => $b->isSent(),
            'recipient_count' => $b->recipientCount(),
            'created_at' => $b->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function template(NotificationTemplate $t): array
    {
        return [
            'id' => $t->id(),
            'key' => $t->key(),
            'channel' => $t->channel()->value,
            'locale' => $t->locale(),
            'subject' => $t->subject(),
            'body' => $t->body(),
        ];
    }
}
