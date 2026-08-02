<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Application\Service;

use EruoFood\Shared\Domain\DomainEvent;

/**
 * The decoupling bridge: turns published {@see DomainEvent}s from any context
 * into loyalty points and referral qualification, driven purely by the config
 * earn-rule map and the referral qualifying-event setting. It never imports
 * another context's event classes — it keys off each event's stable name and
 * reads the user id and amount from the event's public properties via
 * reflection. This is how members earn points without any module knowing Loyalty
 * exists.
 *
 * @phpstan-type EarnRule array{reason: string, user_field: string, amount_field: string|null, per_minor: float, points: int}
 */
final readonly class EventTranslator
{
    /**
     * @param array<string, EarnRule> $earnRules external event name => how to award points
     * @param array{event: string, user_field: string} $referralQualifying
     */
    public function __construct(
        private LoyaltyService $loyalty,
        private ReferralService $referrals,
        private array $earnRules,
        private array $referralQualifying,
    ) {
    }

    public function handle(DomainEvent $event): void
    {
        /** @var array<string, mixed> $vars */
        $vars = get_object_vars($event);
        $name = $event->eventName();

        $rule = $this->earnRules[$name] ?? null;
        if ($rule !== null) {
            $this->applyEarnRule($rule, $vars);
        }

        if ($name === ($this->referralQualifying['event'] ?? null)) {
            $userId = $this->stringField($vars, $this->referralQualifying['user_field'] ?? 'userId');
            if ($userId !== null) {
                $this->referrals->qualify($userId);
            }
        }
    }

    /**
     * @param EarnRule $rule
     * @param array<string, mixed> $vars
     */
    private function applyEarnRule(array $rule, array $vars): void
    {
        $userId = $this->stringField($vars, $rule['user_field']);
        if ($userId === null) {
            return;
        }

        $points = (int) $rule['points'];
        if ($rule['amount_field'] !== null && $rule['per_minor'] > 0.0) {
            $amount = $this->intField($vars, $rule['amount_field']);
            $points += (int) floor($amount * $rule['per_minor']);
        }

        $this->loyalty->earn($userId, $points, $rule['reason'], null);
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
        if (is_object($value) && method_exists($value, '__toString')) {
            $string = (string) $value;

            return $string !== '' ? $string : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function intField(array $vars, string $key): int
    {
        $value = $vars[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
