<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Port;

/**
 * Encrypts/decrypts sensitive at-rest fields (e.g. provider tokens, bank
 * details). A port over the framework's encrypter so the domain/application
 * layers never depend on Laravel's Crypt facade directly.
 */
interface FieldEncryptor
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): string;
}
