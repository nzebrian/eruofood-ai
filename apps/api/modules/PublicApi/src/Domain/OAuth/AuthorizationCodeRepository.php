<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\OAuth;

/** Persistence port for single-use authorization codes. */
interface AuthorizationCodeRepository
{
    public function nextIdentity(): string;

    public function findByHash(string $hashedCode): ?AuthorizationCode;

    public function save(AuthorizationCode $code): void;
}
