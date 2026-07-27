<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller;

use EruoFood\Payments\Application\Input\InitiatePaymentInput;
use EruoFood\Payments\Application\Service\PaymentService;
use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Application\Service\WalletService;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Wallet\WalletTransaction;
use EruoFood\Payments\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The authenticated user's customer wallet: balance, statement, top-up, transfer. */
final readonly class WalletController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private WalletService $wallets,
        private PaymentService $payments,
        private PaymentsPresenter $presenter,
        private string $currency,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->wallets->getOrOpen(WalletOwnerType::Customer, $this->currentUserId($request));

        return $this->data($this->presenter->wallet($wallet));
    }

    public function statement(Request $request): JsonResponse
    {
        $wallet = $this->wallets->getOrOpen(WalletOwnerType::Customer, $this->currentUserId($request));
        $page = $this->wallets->statement($wallet->id(), (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (WalletTransaction $t): array => $this->presenter->walletTransaction($t));
    }

    /** Top up the wallet: take a payment, then credit the balance on capture. */
    public function topUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'customer_email' => ['required', 'email'],
            'provider' => ['nullable', 'in:paystack,flutterwave,moniepoint,stripe,paypal,mock'],
        ]);
        $userId = $this->currentUserId($request);

        $opened = $this->payments->open(InitiatePaymentInput::fromArray([
            'payer_user_id' => $userId,
            'customer_email' => (string) $data['customer_email'],
            'amount_minor' => (int) $data['amount_minor'],
            'method_type' => 'card',
            'provider' => $data['provider'] ?? null,
        ], $this->currency), (string) $request->ip());

        // When the provider captures immediately (e.g. mock/wallet), credit now.
        if ($opened->payment->status()->isCaptured()) {
            $wallet = $this->wallets->getOrOpen(WalletOwnerType::Customer, $userId);
            $this->wallets->credit($wallet, (int) $data['amount_minor'], TransactionType::Topup, $opened->payment->reference(), 'Wallet top-up');
        }

        return $this->data($this->presenter->paymentIntent($opened->payment, $opened->authorizationUrl), 201);
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to_user_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $this->wallets->transfer(
            WalletOwnerType::Customer,
            $this->currentUserId($request),
            WalletOwnerType::Customer,
            (string) $data['to_user_id'],
            (int) $data['amount_minor'],
            isset($data['note']) ? (string) $data['note'] : null,
        );
        $wallet = $this->wallets->getOrOpen(WalletOwnerType::Customer, $this->currentUserId($request));

        return $this->data($this->presenter->wallet($wallet));
    }
}
