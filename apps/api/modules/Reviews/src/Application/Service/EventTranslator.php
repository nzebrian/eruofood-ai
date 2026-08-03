<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Application\Service;

use EruoFood\Reviews\Domain\Eligibility\PurchaseEligibilityRepository;
use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\ValueObject\Subject;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * The decoupling bridge for verified purchases: folds published order/interaction
 * {@see DomainEvent}s from any context into the verified-purchase ledger, driven
 * purely by the config eligibility-map. It never imports another context's event
 * classes — it keys off the event's stable name and reads the buyer id and the
 * subject id from the event's public properties via reflection. This is how
 * Reviews learns who bought what without any module knowing Reviews exists.
 */
final readonly class EventTranslator
{
    /**
     * @param array<string, array{subject_type: string, subject_field: string, user_field: string}> $eligibilityMap
     *                                                                                                              external event name => how to read (subject type, subject-id field, buyer-id field)
     */
    public function __construct(
        private PurchaseEligibilityRepository $eligibility,
        private array $eligibilityMap,
    ) {
    }

    public function handle(DomainEvent $event): void
    {
        $mapping = $this->eligibilityMap[$event->eventName()] ?? null;
        if ($mapping === null) {
            return;
        }

        $type = SubjectType::tryFrom($mapping['subject_type']);
        if ($type === null) {
            return;
        }

        /** @var array<string, mixed> $vars */
        $vars = get_object_vars($event);
        $userId = $this->stringField($vars, $mapping['user_field']);
        $subjectId = $this->stringField($vars, $mapping['subject_field']);
        if ($userId === null || $subjectId === null) {
            return;
        }

        $this->eligibility->record($userId, new Subject($type, $subjectId));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function stringField(array $vars, string $key): ?string
    {
        $value = $vars[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            $string = (string) $value;

            return $string !== '' ? $string : null;
        }

        return null;
    }
}
