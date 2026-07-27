<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\SettlementStatus;
use EruoFood\Payments\Domain\Event\SettlementCompleted;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Shared\Domain\AggregateRoot;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A settlement run for one payee (vendor/restaurant/driver) over a period: it
 * captures the gross of their captured sales, the platform commission and fees
 * deducted, and the resulting net that is paid out. Guarded status lifecycle;
 * emits {@see SettlementCompleted} when funds are released.
 */
final class Settlement extends AggregateRoot
{
    private function __construct(
        private readonly string $id,
        private readonly string $payeeType,
        private readonly string $payeeId,
        private readonly Money $gross,
        private readonly Money $commission,
        private readonly Money $fees,
        private readonly Money $net,
        private SettlementStatus $status,
        private ?string $payoutId,
        private readonly DateTimeImmutable $periodStart,
        private readonly DateTimeImmutable $periodEnd,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $completedAt,
    ) {
    }

    public static function open(
        string $id,
        string $payeeType,
        string $payeeId,
        Money $gross,
        Money $commission,
        Money $fees,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $now,
    ): self {
        $net = $gross->subtract($commission)->subtract($fees);
        if ($net->minorUnits < 0) {
            throw new PaymentsInvalidState('Settlement net cannot be negative.');
        }

        return new self(
            $id, $payeeType, $payeeId, $gross, $commission, $fees, $net,
            SettlementStatus::Pending, null, $periodStart, $periodEnd, $now, null,
        );
    }

    public static function reconstitute(
        string $id,
        string $payeeType,
        string $payeeId,
        Money $gross,
        Money $commission,
        Money $fees,
        Money $net,
        SettlementStatus $status,
        ?string $payoutId,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $completedAt,
    ): self {
        return new self(
            $id, $payeeType, $payeeId, $gross, $commission, $fees, $net, $status,
            $payoutId, $periodStart, $periodEnd, $createdAt, $completedAt,
        );
    }

    public function markProcessing(): void
    {
        $this->transition(SettlementStatus::Processing);
    }

    public function complete(string $payoutId, DateTimeImmutable $at): void
    {
        $this->transition(SettlementStatus::Completed);
        $this->payoutId = $payoutId;
        $this->completedAt = $at;
        $this->recordThat(new SettlementCompleted(
            $this->id, $this->payeeType, $this->payeeId, $this->net->minorUnits, $this->net->currency,
        ));
    }

    public function fail(): void
    {
        $this->transition(SettlementStatus::Failed);
    }

    private function transition(SettlementStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new PaymentsInvalidState(sprintf('Cannot move a settlement from "%s" to "%s".', $this->status->value, $next->value));
        }
        $this->status = $next;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function payeeType(): string
    {
        return $this->payeeType;
    }

    public function payeeId(): string
    {
        return $this->payeeId;
    }

    public function gross(): Money
    {
        return $this->gross;
    }

    public function commission(): Money
    {
        return $this->commission;
    }

    public function fees(): Money
    {
        return $this->fees;
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function status(): SettlementStatus
    {
        return $this->status;
    }

    public function payoutId(): ?string
    {
        return $this->payoutId;
    }

    public function periodStart(): DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function periodEnd(): DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }
}
