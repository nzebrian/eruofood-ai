<?php

declare(strict_types=1);

use EruoFood\Identity\Domain\ValueObject\Email;
use EruoFood\Identity\Domain\ValueObject\PhoneNumber;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

it('normalises email to lowercase', function (): void {
    expect((new Email('Ada@Example.COM'))->value)->toBe('ada@example.com');
});

it('rejects an invalid email', function (): void {
    new Email('not-an-email');
})->throws(InvalidArgumentException::class);

it('accepts a valid E.164 phone number', function (): void {
    expect((new PhoneNumber('+234 801 234 5678'))->value)->toBe('+2348012345678');
});

it('rejects a malformed phone number', function (): void {
    new PhoneNumber('08012345678'); // missing country code
})->throws(InvalidArgumentException::class);
