<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use EruoFood\Payments\Domain\Method\SavedPaymentMethod;
use EruoFood\Payments\Domain\Payment\Payment;
use EruoFood\Payments\Domain\Payment\Refund;
use EruoFood\Payments\Domain\Settlement\MerchantPayable;
use EruoFood\Payments\Domain\Settlement\PayableAccrual;
use EruoFood\Payments\Domain\Settlement\Payout;
use EruoFood\Payments\Domain\Settlement\PayoutAttempt;
use EruoFood\Payments\Domain\Settlement\ReconciliationCase;
use EruoFood\Payments\Domain\Settlement\Settlement;
use EruoFood\Payments\Domain\Settlement\SettlementRun;
use EruoFood\Payments\Domain\Subscription\Subscription;
use EruoFood\Payments\Domain\ValueObject\PaymentSplit;
use EruoFood\Payments\Domain\Wallet\Wallet;
use EruoFood\Payments\Domain\Wallet\WalletTransaction;

/** Maps Payments aggregates to API-shaped arrays. */
final readonly class PaymentsPresenter
{
    /** @return array<string, mixed> */
    public function payment(Payment $p): array
    {
        return [
            'id' => $p->id(),
            'reference' => $p->reference(),
            'order_id' => $p->orderId(),
            'payer_user_id' => $p->payerUserId(),
            'amount_minor' => $p->amount()->minorUnits,
            'refunded_minor' => $p->refundedAmount()->minorUnits,
            'currency' => $p->amount()->currency,
            'status' => $p->status()->value,
            'provider' => $p->provider()->value,
            'method_type' => $p->methodType()->value,
            'provider_reference' => $p->providerReference()?->reference,
            'splits' => array_map(static fn (PaymentSplit $s): array => $s->toArray(), $p->splits()),
            'failure_reason' => $p->failureReason(),
            'timeline' => $p->timeline(),
            'created_at' => $p->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function paymentIntent(Payment $p, ?string $authorizationUrl): array
    {
        return [
            'payment_id' => $p->id(),
            'reference' => $p->reference(),
            'status' => $p->status()->value,
            'provider' => $p->provider()->value,
            'authorization_url' => $authorizationUrl,
        ];
    }

    /** @return array<string, mixed> */
    public function wallet(Wallet $w): array
    {
        return [
            'id' => $w->id(),
            'owner_type' => $w->ownerType()->value,
            'owner_id' => $w->ownerId(),
            'balance_minor' => $w->balance()->minorUnits,
            'currency' => $w->currency(),
            'created_at' => $w->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function walletTransaction(WalletTransaction $t): array
    {
        return $t->toArray();
    }

    /** @return array<string, mixed> */
    public function refund(Refund $r): array
    {
        return [
            'id' => $r->id(),
            'payment_id' => $r->paymentId(),
            'order_id' => $r->orderId(),
            'amount_minor' => $r->amount()->minorUnits,
            'currency' => $r->amount()->currency,
            'partial' => $r->isPartial(),
            'reason' => $r->reason(),
            'status' => $r->status()->value,
            'created_at' => $r->createdAt()->format(DATE_ATOM),
            'completed_at' => $r->completedAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function settlement(Settlement $s): array
    {
        return [
            'id' => $s->id(),
            'payee_type' => $s->payeeType(),
            'payee_id' => $s->payeeId(),
            'gross_minor' => $s->gross()->minorUnits,
            'commission_minor' => $s->commission()->minorUnits,
            'fees_minor' => $s->fees()->minorUnits,
            'net_minor' => $s->net()->minorUnits,
            'currency' => $s->net()->currency,
            'status' => $s->status()->value,
            'payout_id' => $s->payoutId(),
            'period_start' => $s->periodStart()->format(DATE_ATOM),
            'period_end' => $s->periodEnd()->format(DATE_ATOM),
            'completed_at' => $s->completedAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function payout(Payout $p): array
    {
        return [
            'id' => $p->id(),
            'payee_type' => $p->payeeType(),
            'payee_id' => $p->payeeId(),
            'amount_minor' => $p->amount()->minorUnits,
            'currency' => $p->amount()->currency,
            'destination' => $p->destination()->toArray(),
            'status' => $p->status()->value,
            'provider_reference' => $p->providerReference(),
            'paid_at' => $p->paidAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function payable(MerchantPayable $payable): array
    {
        return [
            'merchant_type' => $payable->merchantType,
            'merchant_id' => $payable->merchantId,
            'payable_minor' => $payable->amount()->minorUnits,
            'currency' => $payable->amount()->currency,
            'settleable' => $payable->isSettleable(),
            'overdrawn' => $payable->isOverdrawn(),
        ];
    }

    /** @return array<string, mixed> */
    public function accrual(PayableAccrual $a): array
    {
        return [
            'id' => $a->id(),
            'type' => $a->type()->value,
            'order_id' => $a->orderId(),
            'gross_minor' => $a->gross()->minorUnits,
            'commission_minor' => $a->commission()->minorUnits,
            'fee_minor' => $a->fee()->minorUnits,
            'net_minor' => $a->net()->minorUnits,
            'currency' => $a->net()->currency,
            'commission_rate_bps' => $a->commissionRateBps(),
            'settleable' => $a->isSettleable(),
            'accrued_at' => $a->accruedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * A settlement run for an API response.
     *
     * Carries `server_phase` alongside the run's own state so a client can
     * render the right thing without learning ten settlement states — the
     * projection the cross-cutting foundation exists to provide. It does not
     * carry `computed_by`/`approved_by`/`executed_by`: who authorised a payout
     * is audit-trail information, not something to hand to a merchant.
     *
     * @return array<string, mixed>
     */
    public function settlementRun(SettlementRun $r): array
    {
        return [
            'id' => $r->id(),
            'merchant_type' => $r->merchantType(),
            'merchant_id' => $r->merchantId(),
            'reference' => $r->settlementReference(),
            'window_start' => $r->windowStart()->format(DATE_ATOM),
            'window_end' => $r->windowEnd()->format(DATE_ATOM),
            'gross_minor' => $r->gross()->minorUnits,
            'commission_minor' => $r->commission()->minorUnits,
            'fee_minor' => $r->fee()->minorUnits,
            'net_minor' => $r->net()->minorUnits,
            'currency' => $r->currency(),
            'state' => $r->state()->value,
            'server_phase' => $r->state()->serverPhase()->value,
            'requires_reconciliation' => $r->state()->requiresReconciliation(),
            'failure_reason' => $r->failureReason(),
            'created_at' => $r->createdAt()->format(DATE_ATOM),
            'updated_at' => $r->updatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * An operator's view of one transfer attempt.
     *
     * The provider reference is included because an operator matching our
     * records against a provider dashboard needs it. It is an admin surface
     * only — {@see settlementRun()} is what a merchant sees, and it has none.
     *
     * @return array<string, mixed>
     */
    public function payoutAttempt(PayoutAttempt $a): array
    {
        return [
            'id' => $a->id(),
            'settlement_run_id' => $a->settlementRunId(),
            'attempt_no' => $a->attemptNo(),
            'provider' => $a->provider()->value,
            'provider_reference' => $a->providerReference(),
            'amount_minor' => $a->amount()->minorUnits,
            'currency' => $a->amount()->currency,
            'state' => $a->state()->value,
            'server_phase' => $a->state()->serverPhase()->value,
            'failure_reason' => $a->failureReason(),
            'created_at' => $a->createdAt()->format(DATE_ATOM),
            'submitted_at' => $a->submittedAt()?->format(DATE_ATOM),
            'settled_at' => $a->settledAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function reconciliationCase(ReconciliationCase $c): array
    {
        return [
            'id' => $c->id(),
            'kind' => $c->kind()->value,
            'subject_type' => $c->subjectType(),
            'subject_id' => $c->subjectId(),
            'expected_minor' => $c->expected()->minorUnits,
            'observed_minor' => $c->observed()->minorUnits,
            'difference_minor' => $c->differenceMinor(),
            'currency' => $c->expected()->currency,
            'state' => $c->state()->value,
            'detail' => $c->detail(),
            'opened_at' => $c->openedAt()->format(DATE_ATOM),
            'resolved_at' => $c->resolvedAt()?->format(DATE_ATOM),
            'resolved_by' => $c->resolvedBy(),
            'resolution_note' => $c->resolutionNote(),
            'compensating_posting_id' => $c->compensatingPostingId(),
        ];
    }

    /** @return array<string, mixed> */
    public function savedMethod(SavedPaymentMethod $m): array
    {
        return [
            'id' => $m->id(),
            'provider' => $m->provider()->value,
            'brand' => $m->card()->brand,
            'last4' => $m->card()->last4,
            'expiry_month' => $m->card()->expiryMonth,
            'expiry_year' => $m->card()->expiryYear,
            'label' => $m->card()->masked(),
            'default' => $m->isDefault(),
            'created_at' => $m->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function subscription(Subscription $s): array
    {
        return [
            'id' => $s->id(),
            'plan' => $s->plan(),
            'amount_minor' => $s->amount()->minorUnits,
            'currency' => $s->amount()->currency,
            'interval' => $s->interval(),
            'status' => $s->status()->value,
            'next_billing_at' => $s->nextBillingAt()->format(DATE_ATOM),
        ];
    }
}
