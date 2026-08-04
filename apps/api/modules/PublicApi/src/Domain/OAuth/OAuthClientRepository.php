<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\OAuth;

/** Persistence port for OAuth2 clients. */
interface OAuthClientRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?OAuthClient;

    public function save(OAuthClient $client): void;
}
