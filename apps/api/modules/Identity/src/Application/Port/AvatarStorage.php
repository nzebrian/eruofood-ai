<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Port;

use EruoFood\Identity\Domain\ValueObject\UserId;

/** Stores profile photos in S3-compatible object storage and resolves URLs. */
interface AvatarStorage
{
    /** Store raw image bytes; returns the stored object path/key. */
    public function store(UserId $userId, string $contents, string $extension): string;

    public function delete(string $path): void;

    public function url(string $path): string;
}
