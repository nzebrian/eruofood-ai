<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Security;

use EruoFood\Payments\Application\Port\FieldEncryptor;
use Illuminate\Contracts\Encryption\Encrypter;

/** Encrypts sensitive at-rest fields via the framework's encrypter. */
final readonly class LaravelFieldEncryptor implements FieldEncryptor
{
    public function __construct(private Encrypter $encrypter)
    {
    }

    public function encrypt(string $plaintext): string
    {
        return $this->encrypter->encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        return $this->encrypter->decryptString($ciphertext);
    }
}
