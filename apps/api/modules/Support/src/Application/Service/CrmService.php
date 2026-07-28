<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Service;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Support\Application\Port\AiSupportAssistant;
use EruoFood\Support\Domain\Crm\CustomerProfile;
use EruoFood\Support\Domain\Crm\CustomerProfileRepository;
use EruoFood\Support\Domain\Crm\CustomerSegment;
use EruoFood\Support\Domain\Crm\Interaction;
use EruoFood\Support\Domain\Crm\InteractionRepository;

/**
 * The CRM: a support-owned projection of the customer, maintained from domain
 * events (registration, orders, payments) and ticket activity, plus the unified
 * interaction timeline, tagging, agent notes, AI insights and segmentation. It
 * reads no other context's tables — everything arrives as events.
 */
final readonly class CrmService
{
    /**
     * @param array<string, int> $segmentThresholds
     */
    public function __construct(
        private CustomerProfileRepository $profiles,
        private InteractionRepository $interactions,
        private AiSupportAssistant $assistant,
        private array $segmentThresholds,
    ) {
    }

    public function getOrCreate(string $userId, ?string $displayName = null, ?string $email = null): CustomerProfile
    {
        $profile = $this->profiles->findByUserId($userId);
        if ($profile === null) {
            $profile = CustomerProfile::start($userId, $displayName, $email, new DateTimeImmutable());
            $this->profiles->save($profile);
        } elseif ($displayName !== null || $email !== null) {
            $profile->identify($displayName, $email);
            $this->profiles->save($profile);
        }

        return $profile;
    }

    public function onTicketOpened(string $userId, string $summary, string $ref): void
    {
        $profile = $this->getOrCreate($userId);
        $now = new DateTimeImmutable();
        $profile->recordTicket($now);
        $this->profiles->save($profile);
        $this->appendInteraction($userId, 'ticket.opened', $summary, $ref, 'support', $now);
    }

    /**
     * Fold a published cross-context event into the timeline + profile.
     */
    public function onExternalEvent(string $kind, string $userId, string $summary, ?string $ref, ?int $amountMinor): void
    {
        $now = new DateTimeImmutable();
        $profile = $this->getOrCreate($userId);
        if ($kind === 'order.placed') {
            $profile->recordOrder($amountMinor ?? 0, $this->segmentThresholds, $now);
        } else {
            $profile->touch($now);
        }
        $this->profiles->save($profile);
        $this->appendInteraction($userId, $kind, $summary, $ref, 'event', $now);
    }

    public function timeline(string $userId, int $page, int $perPage): Paginated
    {
        return $this->interactions->forUser($userId, $page, $perPage);
    }

    /**
     * @return Paginated<CustomerProfile>
     */
    public function search(?string $term, ?CustomerSegment $segment, int $page, int $perPage): Paginated
    {
        return $this->profiles->search($term, $segment, $page, $perPage);
    }

    /** @return array<string, int> */
    public function segmentCounts(): array
    {
        return $this->profiles->segmentCounts();
    }

    public function addTag(string $userId, string $tag): CustomerProfile
    {
        $profile = $this->getOrCreate($userId);
        $profile->addTag($tag);
        $this->profiles->save($profile);

        return $profile;
    }

    public function setNotes(string $userId, string $notes): CustomerProfile
    {
        $profile = $this->getOrCreate($userId);
        $profile->setNotes($notes);
        $this->profiles->save($profile);

        return $profile;
    }

    public function generateInsight(string $userId): CustomerProfile
    {
        $profile = $this->getOrCreate($userId);
        $insight = $this->assistant->customerInsight([
            'segment' => $profile->segment()->value,
            'orders' => $profile->orderCount(),
            'total_spent_minor' => $profile->totalSpentMinor(),
            'tickets' => $profile->ticketCount(),
        ]);
        $profile->setInsight($insight, new DateTimeImmutable());
        $this->profiles->save($profile);

        return $profile;
    }

    private function appendInteraction(string $userId, string $kind, string $summary, ?string $ref, string $source, DateTimeImmutable $at): void
    {
        $this->interactions->append(new Interaction(
            $this->interactions->nextIdentity(),
            $userId,
            $kind,
            $summary,
            $ref,
            $source,
            $at,
        ));
    }
}
