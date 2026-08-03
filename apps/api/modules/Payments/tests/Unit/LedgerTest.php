<?php

declare(strict_types=1);

use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Ledger\IdentityGenerator;
use EruoFood\Payments\Domain\Ledger\LedgerPosting;
use EruoFood\Shared\Domain\ValueObject\Money;

function seqIds(): IdentityGenerator
{
    return new class () implements IdentityGenerator {
        private int $n = 0;

        public function next(): string
        {
            return 'e'.(++$this->n);
        }
    };
}

it('accepts a balanced capture posting', function (): void {
    $posting = new LedgerPosting('corr-1', TransactionType::Payment, 'PMT-REF', new DateTimeImmutable(), seqIds());
    $posting->debit(LedgerAccount::Cash, new Money(1000000, 'NGN'))
        ->credit(LedgerAccount::Escrow, new Money(900000, 'NGN'))
        ->credit(LedgerAccount::Commission, new Money(100000, 'NGN'));

    $entries = $posting->balanced();
    expect($entries)->toHaveCount(3);
});

it('rejects an unbalanced posting', function (): void {
    $posting = new LedgerPosting('corr-2', TransactionType::Payment, null, new DateTimeImmutable(), seqIds());
    $posting->debit(LedgerAccount::Cash, new Money(1000000, 'NGN'))
        ->credit(LedgerAccount::Escrow, new Money(900000, 'NGN'));

    expect(fn () => $posting->balanced())->toThrow(PaymentsInvalidState::class);
});
