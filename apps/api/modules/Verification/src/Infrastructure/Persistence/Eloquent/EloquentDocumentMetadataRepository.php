<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Verification\Application\Port\FieldEncryptor;
use EruoFood\Verification\Domain\Document\DocumentMetadata;
use EruoFood\Verification\Domain\Document\DocumentMetadataRepository;
use EruoFood\Verification\Domain\Enum\DocumentType;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationCaseModel;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationDocumentModel;
use Illuminate\Support\Str;
use Throwable;

/**
 * Document metadata storage.
 *
 * The last-four fragment is encrypted on the way in and decrypted on the way
 * out, so the domain object always holds plaintext and the column never does —
 * the same arrangement the business registration number uses. Keeping the
 * crypto boundary at the repository is what stops a future reader of the table
 * (a backup, an analytics replica, a support query) from seeing the fragment,
 * without every caller having to remember to decrypt.
 */
final class EloquentDocumentMetadataRepository implements DocumentMetadataRepository
{
    public function __construct(private FieldEncryptor $encryptor)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function forCase(string $caseId): array
    {
        $models = VerificationDocumentModel::query()
            ->where('case_id', $caseId)
            ->orderBy('created_at')
            ->get();

        return array_values(array_map(fn (VerificationDocumentModel $m): DocumentMetadata => new DocumentMetadata(
            id: $m->id,
            caseId: $m->case_id,
            documentType: DocumentType::from($m->document_type),
            issuingCountry: $m->issuing_country,
            numberLast4: $m->number_last4 === null ? null : $this->decrypt($m->number_last4),
            expiresOn: $m->expires_on !== null ? DateTimeImmutable::createFromInterface($m->expires_on) : null,
            providerReference: $m->provider_reference,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        ), $models->all()));
    }

    public function save(DocumentMetadata $metadata): void
    {
        $model = VerificationDocumentModel::query()->find($metadata->id) ?? new VerificationDocumentModel();
        $model->id = $metadata->id;
        $model->case_id = $metadata->caseId;
        $model->document_type = $metadata->documentType->value;
        $model->issuing_country = $metadata->issuingCountry;
        $model->number_last4 = $metadata->numberLast4 === null
            ? null
            : $this->encryptor->encrypt($metadata->numberLast4);
        $model->expires_on = $metadata->expiresOn;
        $model->provider_reference = $metadata->providerReference;
        $model->created_at = $metadata->createdAt;
        $model->save();
    }

    /**
     * Drop metadata for cases that closed before the retention cutoff.
     *
     * Only closed cases: an open or currently-verified case still needs its
     * document facts. The case and its audit history are deliberately kept — what
     * expires is the regulated detail, not the record that a decision was made.
     */
    public function purgeClosedBefore(DateTimeImmutable $before): int
    {
        $caseIds = VerificationCaseModel::query()
            ->whereIn('status', ['rejected', 'expired', 'reverification_required'])
            ->where('updated_at', '<', $before)
            ->pluck('id');

        if ($caseIds->isEmpty()) {
            return 0;
        }

        return VerificationDocumentModel::query()->whereIn('case_id', $caseIds)->delete();
    }

    private function decrypt(string $ciphertext): string
    {
        try {
            return $this->encryptor->decrypt($ciphertext);
        } catch (Throwable) {
            // Unreadable (rotated key, corrupted row). Returning empty keeps the
            // case loadable — the reviewer loses one fragment, not the record of
            // the decision.
            return '';
        }
    }
}
