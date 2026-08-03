<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Seeder;

use DateTimeImmutable;
use EruoFood\Shared\Domain\ValueObject\Slug;
use EruoFood\Support\Domain\Automation\AutomationRule;
use EruoFood\Support\Domain\Automation\AutomationRuleRepository;
use EruoFood\Support\Domain\Enum\TicketPriority;
use EruoFood\Support\Domain\Knowledge\Article;
use EruoFood\Support\Domain\Knowledge\ArticleRepository;
use EruoFood\Support\Domain\Knowledge\ArticleStatus;
use EruoFood\Support\Domain\Sla\SlaPolicy;
use EruoFood\Support\Domain\Sla\SlaPolicyRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the default SLA policies (from config), a starter auto-routing rule, and
 * a couple of published help articles so the helpdesk is usable out of the box.
 * Idempotent — existing rows are left untouched.
 */
final class SupportSeeder extends Seeder
{
    public function run(): void
    {
        /** @var SlaPolicyRepository $policies */
        $policies = app(SlaPolicyRepository::class);
        /** @var array<string, array{first_response: int, resolution: int}> $sla */
        $sla = (array) config('support.sla', []);
        foreach ($sla as $priorityValue => $targets) {
            $priority = TicketPriority::tryFrom((string) $priorityValue);
            if ($priority === null || $policies->findByPriority($priority) !== null) {
                continue;
            }
            $policies->save(SlaPolicy::define(
                (string) Str::orderedUuid(),
                ucfirst((string) $priorityValue).' priority SLA',
                $priority,
                (int) $targets['first_response'],
                (int) $targets['resolution'],
            ));
        }

        /** @var AutomationRuleRepository $rules */
        $rules = app(AutomationRuleRepository::class);
        if ($rules->all() === []) {
            $rules->save(AutomationRule::create(
                (string) Str::orderedUuid(),
                'Flag urgent tickets for review',
                'ticket_opened',
                [['field' => 'priority', 'op' => 'eq', 'value' => 'urgent']],
                [
                    ['type' => 'add_tag', 'value' => 'urgent-review'],
                    ['type' => 'add_note', 'value' => 'Auto-flagged: urgent ticket awaiting immediate triage.'],
                ],
                0,
            ));
        }

        /** @var ArticleRepository $articles */
        $articles = app(ArticleRepository::class);
        $now = new DateTimeImmutable();
        foreach ($this->articles() as [$title, $category, $body]) {
            $slug = Slug::fromTitle($title);
            if ($articles->findBySlug($slug) !== null) {
                continue;
            }
            $article = Article::draft($articles->nextIdentity(), $slug, $title, $body, null, $category, [], null, $now);
            $article->publish($now);
            $articles->save($article);
        }
        unset($now, $article);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function articles(): array
    {
        return [
            ['How do I track my order?', 'orders', 'Open the Orders page to see live status and rider location for every order.'],
            ['How do I request a refund?', 'payments', 'From a completed order choose "Request refund". Approved refunds return to your wallet or card.'],
            ['How do I contact support?', 'general', 'Use the in-app support centre to open a ticket, or start a live chat. We aim to respond within our SLA.'],
        ];
    }
}
