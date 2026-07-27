<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\ValueObject;

/** A payout destination — a bank account (NUBAN) at a Nigerian bank. */
final readonly class BankAccount
{
    public function __construct(
        public string $accountName,
        public string $accountNumber,
        public string $bankCode,
        public ?string $bankName = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['account_name'] ?? ''),
            (string) ($data['account_number'] ?? ''),
            (string) ($data['bank_code'] ?? ''),
            isset($data['bank_name']) ? (string) $data['bank_name'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_name' => $this->accountName,
            'account_number' => $this->accountNumber,
            'bank_code' => $this->bankCode,
            'bank_name' => $this->bankName,
        ];
    }
}
