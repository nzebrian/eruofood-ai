<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Storage;

use EruoFood\Identity\Application\Port\AvatarStorage;
use EruoFood\Identity\Domain\ValueObject\UserId;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Stores avatars on the configured (S3-compatible) disk under private keys and
 * serves them via time-limited signed URLs where supported.
 */
final readonly class S3AvatarStorage implements AvatarStorage
{
    public function __construct(private Filesystem $disk)
    {
    }

    public function store(UserId $userId, string $contents, string $extension): string
    {
        $path = sprintf('avatars/%s/%s.%s', $userId->value(), Str::random(24), ltrim($extension, '.'));
        $this->disk->put($path, $contents);

        return $path;
    }

    public function delete(string $path): void
    {
        if ($this->disk->exists($path)) {
            $this->disk->delete($path);
        }
    }

    public function url(string $path): string
    {
        return $this->disk->url($path);
    }
}
