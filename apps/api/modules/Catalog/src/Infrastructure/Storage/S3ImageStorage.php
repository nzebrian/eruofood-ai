<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Storage;

use EruoFood\Catalog\Application\Port\ImageStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;

/** Stores catalogue media on the configured (S3-compatible) disk. */
final readonly class S3ImageStorage implements ImageStorage
{
    public function __construct(private Filesystem $disk)
    {
    }

    public function store(string $folder, string $contents, string $extension): string
    {
        $path = sprintf('%s/%s.%s', trim($folder, '/'), Str::random(24), ltrim($extension, '.'));
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
