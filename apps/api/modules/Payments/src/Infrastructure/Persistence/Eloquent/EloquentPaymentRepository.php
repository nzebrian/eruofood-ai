<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\PaymentMethodType;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Enum\PaymentStatus;
use EruoFood\Payments\Domain\Payment\Payment;
use EruoFood\Payments\Domain\Payment\PaymentRepository;
use EruoFood\Payments\Domain\ValueObject\PaymentSplit;
use EruoFood\Payments\Domain\ValueObject\ProviderReference;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PaymentModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Str;

final class EloquentPaymentRepository implements PaymentRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function nextReference(): string
    {
        return 'PMT-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(6));
    }

    public function findById(string $id): ?Payment
    {
        $m = PaymentModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByIdForUpdate(string $id): ?Payment
    {
        $m = PaymentModel::query()->whereKey($id)->lockForUpdate()->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByReference(string $reference): ?Payment
    {
        $m = PaymentModel::query()->where('reference', $reference)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByIdempotencyKey(string $key): ?Payment
    {
        $m = PaymentModel::query()->where('idempotency_key', $key)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByProviderReference(string $provider, string $reference): ?Payment
    {
        $m = PaymentModel::query()->where('provider', $provider)->where('provider_reference', $reference)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findCapturedForOrder(string $orderId): ?Payment
    {
        $m = PaymentModel::query()
            ->where('order_id', $orderId)
            ->where('status', PaymentStatus::Succeeded->value)
            // Oldest first, and tie-broken by id, so the answer is stable
            // across runs even for two payments captured in the same second.
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forPayer(string $payerUserId, int $page, int $perPage): Paginated
    {
        $paginator = PaymentModel::query()->where('payer_user_id', $payerUserId)
            ->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return $this->paginate($paginator, $page, $perPage);
    }

    public function all(?PaymentStatus $status, int $page, int $perPage): Paginated
    {
        $query = PaymentModel::query();
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $paginator = $query->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return $this->paginate($paginator, $page, $perPage);
    }

    public function capturedTotals(): array
    {
        $query = PaymentModel::query()->whereIn('status', [
            PaymentStatus::Succeeded->value,
            PaymentStatus::PartiallyRefunded->value,
            PaymentStatus::Refunded->value,
        ]);

        return ['count' => (int) $query->count(), 'gross_minor' => (int) $query->sum('amount_minor')];
    }

    public function save(Payment $payment): void
    {
        $model = PaymentModel::query()->find($payment->id()) ?? new PaymentModel();
        $ref = $payment->providerReference();
        $model->id = $payment->id();
        $model->reference = $payment->reference();
        $model->order_id = $payment->orderId();
        $model->payer_user_id = $payment->payerUserId();
        $model->amount_minor = $payment->amount()->minorUnits;
        $model->refunded_minor = $payment->refundedAmount()->minorUnits;
        $model->currency = $payment->amount()->currency;
        $model->status = $payment->status()->value;
        $model->provider = $payment->provider()->value;
        $model->method_type = $payment->methodType()->value;
        $model->provider_reference = $ref?->reference;
        $model->idempotency_key = $payment->idempotencyKey();
        $model->splits = array_map(static fn (PaymentSplit $s): array => $s->toArray(), $payment->splits());
        $model->failure_reason = $payment->failureReason();
        $model->timeline = $payment->timeline();
        $model->created_at = $payment->createdAt();
        $model->save();
    }

    /**
     * @param \Illuminate\Pagination\LengthAwarePaginator<int, PaymentModel> $paginator
     * @return Paginated<Payment>
     */
    private function paginate(\Illuminate\Pagination\LengthAwarePaginator $paginator, int $page, int $perPage): Paginated
    {
        return new Paginated(
            array_values(array_map(fn (PaymentModel $m): Payment => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    private function toDomain(PaymentModel $m): Payment
    {
        $currency = $m->currency ?: $this->currency;
        $provider = PaymentProvider::from($m->provider);
        $providerRef = $m->provider_reference !== null
            ? new ProviderReference($provider, $m->provider_reference)
            : null;

        return Payment::reconstitute(
            id: $m->id,
            reference: $m->reference,
            orderId: $m->order_id,
            payerUserId: $m->payer_user_id,
            amount: new Money((int) $m->amount_minor, $currency),
            refundedAmount: new Money((int) $m->refunded_minor, $currency),
            status: PaymentStatus::from($m->status),
            provider: $provider,
            methodType: PaymentMethodType::from($m->method_type),
            providerReference: $providerRef,
            idempotencyKey: $m->idempotency_key,
            splits: array_values(array_map(fn (array $s): PaymentSplit => PaymentSplit::fromArray($s, $currency), $m->splits ?? [])),
            failureReason: $m->failure_reason,
            timeline: array_values($m->timeline ?? []),
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
