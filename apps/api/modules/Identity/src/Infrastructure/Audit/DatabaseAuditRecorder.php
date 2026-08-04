<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Audit;

use EruoFood\Identity\Application\Port\AuditRecorder;
use EruoFood\Identity\Domain\ValueObject\UserId;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\AuditLogModel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Writes immutable audit entries to the database. */
final readonly class DatabaseAuditRecorder implements AuditRecorder
{
    public function __construct(private ?Request $request = null)
    {
    }

    public function record(string $action, ?UserId $actor, array $context = []): void
    {
        AuditLogModel::query()->create([
            'id' => (string) Str::orderedUuid(),
            'action' => $action,
            'actor_id' => $actor?->value(),
            'context' => $context,
            'ip_address' => $this->request?->ip(),
        ]);
    }
}
