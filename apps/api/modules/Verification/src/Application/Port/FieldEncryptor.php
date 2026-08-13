<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Port;

/**
 * Encrypts sensitive at-rest fields — registration numbers, document number
 * fragments.
 *
 * A port so the application layer never reaches for the framework's encrypter
 * directly. Mirrors the Payments port of the same name rather than sharing it:
 * the two contexts should be able to rotate keys independently.
 */
interface FieldEncryptor
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): string;
}
