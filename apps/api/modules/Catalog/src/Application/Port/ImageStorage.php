<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Application\Port;

/** Stores catalogue media (food/recipe/step images) on S3-compatible storage. */
interface ImageStorage
{
    /** Store raw bytes under the given folder; returns the stored path/key. */
    public function store(string $folder, string $contents, string $extension): string;

    public function delete(string $path): void;

    public function url(string $path): string;
}
