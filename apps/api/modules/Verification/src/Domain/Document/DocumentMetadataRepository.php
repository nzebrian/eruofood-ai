<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Document;

use DateTimeImmutable;

/** Persistence port for {@see DocumentMetadata}. */
interface DocumentMetadataRepository
{
    public function nextIdentity(): string;

    /** @return list<DocumentMetadata> */
    public function forCase(string $caseId): array;

    public function save(DocumentMetadata $metadata): void;

    /** Delete metadata for cases closed before $before. Returns rows removed. */
    public function purgeClosedBefore(DateTimeImmutable $before): int;
}
