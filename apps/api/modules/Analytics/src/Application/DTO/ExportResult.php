<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\DTO;

/** The bytes + metadata of an exported report, ready to stream as a download. */
final readonly class ExportResult
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $content,
    ) {
    }
}
