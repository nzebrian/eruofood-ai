<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use DateTimeImmutable;
use EruoFood\Payments\Application\Port\CommissionCalculator;
use EruoFood\Payments\Application\Port\PaymentGatewayFactory;
use EruoFood\Payments\Application\Port\PaymentNotifier;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Exception\PaymentsNotFound;
use EruoFood\Payments\Domain\Settlement\Payout;
use EruoFood\Payments\Domain\Settlement\PayoutRepository;
use EruoFood\Payments\Domain\Settlement\Settlement;
use EruoFood\Payments\Domain\Settlement\SettlementRepository;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * The **Settlement Engine**: aggregates a payee's captured sales for a period,
 * deducts platform commission and fees (the Commission Engine), and releases the
 * net either to the payee's wallet or, when bank details are given, as a bank
 * payout via the provider. Posts the ledger and publishes SettlementCompleted.
 */
final readonly class SettlementService
{
    public function __construct(
        private SettlementRepository $settlements,
        private PayoutRepository $payouts,
        private CommissionCalculator $commission,
        private PaymentGatewayFactory $gateways,
        private WalletService $wallets,
        private LedgerService $ledger,
        private PaymentNotifier $notifier,
        private EventBus $events,
        private string $currency,
    ) {
    }

    /**
     * Settle a gross amount to a payee. When $bank is null the net is credited
     * to the payee's wallet; otherwise it is paid out to the bank account.
     */
    public function settle(
        string $payeeType,
        string $payeeId,
        int $grossMinor,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        ?BankAccount $bank = null,
    ): Settlement {
        $now = new DateTimeImmutable();
        $gross = new Money($grossMinor, $this->currency);
        $commission = $this->commission->commissionOn($gross);
        $fees = $this->commission->feeOn($gross);

        $settlement = Settlement::open(
            $this->settlements->nextIdentity(),
            $payeeType,
            $payeeId,
            $gross,
            $commission,
            $fees,
            $periodStart,
            $periodEnd,
            $now,
        );
        $settlement->markProcessing();
        $this->settlements->save($settlement);

        $net = $settlement->net();
        $payoutId = null;

        if ($bank !== null) {
            $payout = Payout::open($this->payouts->nextIdentity(), $payeeType, $payeeId, $net, $bank, $now);
            $result = $this->gateways->default()->transfer($bank, $net, $payout->id());
            if ($result->success) {
                $payout->markProcessing($result->providerReference);
                $payout->markPaid($now);
            } else {
                $payout->fail();
            }
            $this->payouts->save($payout);
            $payoutId = $payout->id();
        } else {
            $wallet = $this->wallets->getOrOpen($this->walletOwnerType($payeeType), $payeeId);
            $this->wallets->credit($wallet, $net->minorUnits, TransactionType::Settlement, $settlement->id(), 'Settlement payout');
        }

        $settlement->complete($payoutId ?? $settlement->id(), $now);
        $this->ledger->recordSettlement($settlement->id(), $settlement->id(), $net);
        $this->settlements->save($settlement);
        foreach ($settlement->releaseEvents() as $event) {
            $this->events->publish($event);
        }
        $this->notifier->settlementCompleted($settlement);

        return $settlement;
    }

    public function getById(string $id): Settlement
    {
        return $this->settlements->findById($id) ?? throw PaymentsNotFound::of('settlement', $id);
    }

    /** @return Paginated<Settlement> */
    public function all(int $page, int $perPage): Paginated
    {
        return $this->settlements->all($page, $perPage);
    }

    /** @return Paginated<Payout> */
    public function payouts(int $page, int $perPage): Paginated
    {
        return $this->payouts->all($page, $perPage);
    }

    private function walletOwnerType(string $payeeType): WalletOwnerType
    {
        return WalletOwnerType::tryFrom($payeeType) ?? WalletOwnerType::Vendor;
    }
}
