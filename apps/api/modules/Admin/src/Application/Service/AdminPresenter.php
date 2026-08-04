<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Service;

use EruoFood\Admin\Application\DTO\UserSummary;
use EruoFood\Admin\Application\DTO\VendorSummary;
use EruoFood\Admin\Domain\Audit\AuditLogEntry;
use EruoFood\Admin\Domain\Cms\Banner;
use EruoFood\Admin\Domain\Cms\CmsPage;
use EruoFood\Admin\Domain\Cms\FaqItem;
use EruoFood\Admin\Domain\Cms\SeoMetadata;
use EruoFood\Admin\Domain\Config\FeatureFlag;
use EruoFood\Admin\Domain\Config\Setting;
use EruoFood\Admin\Domain\Operations\ApprovalRequest;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\Impersonation;
use EruoFood\Admin\Domain\Support\Ticket;
use EruoFood\Admin\Domain\Support\TicketMessage;

/** Maps Admin aggregates and read DTOs to API-shaped arrays. */
final readonly class AdminPresenter
{
    /** @return array<string, mixed> */
    public function account(AdminAccount $a): array
    {
        return [
            'user_id' => $a->userId(),
            'roles' => array_map(static fn ($r): string => $r->value, $a->roles()),
            'extra_permissions' => $a->extraPermissions(),
            'permissions' => $a->permissions(),
            'status' => $a->status()->value,
            'is_super' => $a->isSuper(),
            'created_at' => $a->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function impersonation(Impersonation $i): array
    {
        return [
            'id' => $i->id(),
            'admin_user_id' => $i->adminUserId(),
            'target_user_id' => $i->targetUserId(),
            'reason' => $i->reason(),
            'active' => $i->isActive(),
            'started_at' => $i->startedAt()->format(DATE_ATOM),
            'ended_at' => $i->endedAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function auditEntry(AuditLogEntry $e): array
    {
        return [
            'id' => $e->id(),
            'actor_id' => $e->actorId(),
            'category' => $e->category()->value,
            'action' => $e->action(),
            'subject_type' => $e->subjectType(),
            'subject_id' => $e->subjectId(),
            'context' => $e->context(),
            'ip' => $e->ip(),
            'created_at' => $e->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function page(CmsPage $p): array
    {
        return [
            'id' => $p->id(),
            'type' => $p->type()->value,
            'slug' => $p->slug()->value,
            'title' => $p->title(),
            'body' => $p->body(),
            'excerpt' => $p->excerpt(),
            'seo' => $this->seo($p->seo()),
            'status' => $p->status()->value,
            'author_id' => $p->authorId(),
            'published_at' => $p->publishedAt()?->format(DATE_ATOM),
            'created_at' => $p->createdAt()->format(DATE_ATOM),
            'updated_at' => $p->updatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function seo(SeoMetadata $s): array
    {
        return [
            'meta_title' => $s->metaTitle,
            'meta_description' => $s->metaDescription,
            'keywords' => $s->keywords,
            'og_image' => $s->ogImage,
        ];
    }

    /** @return array<string, mixed> */
    public function banner(Banner $b): array
    {
        return [
            'id' => $b->id(),
            'title' => $b->title(),
            'image_url' => $b->imageUrl(),
            'link_url' => $b->linkUrl(),
            'placement' => $b->placement(),
            'sort_order' => $b->sortOrder(),
            'active' => $b->isActive(),
            'starts_at' => $b->startsAt()?->format(DATE_ATOM),
            'ends_at' => $b->endsAt()?->format(DATE_ATOM),
            'created_at' => $b->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function faq(FaqItem $f): array
    {
        return [
            'id' => $f->id(),
            'question' => $f->question(),
            'answer' => $f->answer(),
            'category' => $f->category(),
            'sort_order' => $f->sortOrder(),
            'published' => $f->isPublished(),
            'updated_at' => $f->updatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function setting(Setting $s): array
    {
        return [
            'key' => $s->key(),
            'group' => $s->group(),
            'value' => $s->displayValue(),
            'secret' => $s->isSecret(),
            'description' => $s->description(),
            'updated_at' => $s->updatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function flag(FeatureFlag $f): array
    {
        return [
            'key' => $f->key(),
            'enabled' => $f->isEnabled(),
            'description' => $f->description(),
            'updated_at' => $f->updatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function approval(ApprovalRequest $r): array
    {
        return [
            'id' => $r->id(),
            'subject_type' => $r->subjectType(),
            'subject_id' => $r->subjectId(),
            'kind' => $r->kind()->value,
            'details' => $r->details(),
            'status' => $r->status()->value,
            'decided_by' => $r->decidedBy(),
            'note' => $r->note(),
            'submitted_at' => $r->submittedAt()->format(DATE_ATOM),
            'decided_at' => $r->decidedAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function ticket(Ticket $t): array
    {
        return [
            'id' => $t->id(),
            'requester_id' => $t->requesterId(),
            'subject' => $t->subject(),
            'category' => $t->category(),
            'status' => $t->status()->value,
            'priority' => $t->priority()->value,
            'assignee_id' => $t->assigneeId(),
            'messages' => array_map(fn (TicketMessage $m): array => $this->ticketMessage($m), $t->messages()),
            'created_at' => $t->createdAt()->format(DATE_ATOM),
            'updated_at' => $t->updatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function ticketMessage(TicketMessage $m): array
    {
        return [
            'id' => $m->id,
            'author_id' => $m->authorId,
            'body' => $m->body,
            'internal' => $m->internal,
            'created_at' => $m->createdAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function user(UserSummary $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'status' => $u->status,
            'verified' => $u->verified,
            'registered_at' => $u->registeredAt?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function vendor(VendorSummary $v): array
    {
        return [
            'id' => $v->id,
            'name' => $v->name,
            'type' => $v->type,
            'status' => $v->status,
        ];
    }
}
