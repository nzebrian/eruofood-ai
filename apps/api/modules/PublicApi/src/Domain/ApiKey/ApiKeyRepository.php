<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\ApiKey;

/** Persistence port for {@see ApiKey} credentials. */
interface ApiKeyRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?ApiKey;

    /** Look up by the public prefix — the first step of authentication. */
    public function findByPrefix(string $prefix): ?ApiKey;

    /**
     * @return list<ApiKey>
     */
    public function forApplication(string $applicationId): array;

    public function save(ApiKey $key): void;
}
