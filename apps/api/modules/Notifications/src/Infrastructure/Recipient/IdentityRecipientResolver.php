<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Recipient;

use EruoFood\Notifications\Application\DTO\Recipient;
use EruoFood\Notifications\Application\Port\RecipientResolver;
use Illuminate\Database\Connection;

/**
 * Resolves a recipient from the identity store.
 *
 * A read-only lookup by id — a soft reference, no join and no import of
 * Identity's classes, the same arrangement the audience provider uses.
 *
 * Selects an explicit column list rather than the whole row, so a column added
 * to `identity_users` later cannot silently become available to templates.
 */
final readonly class IdentityRecipientResolver implements RecipientResolver
{
    public function __construct(
        private Connection $db,
        private string $defaultLocale = 'en',
    ) {
    }

    public function resolve(string $userId): ?Recipient
    {
        if (! $this->db->getSchemaBuilder()->hasTable('identity_users')) {
            return null;
        }

        $row = $this->db->table('identity_users')
            ->where('id', $userId)
            ->first(['id', 'name', 'email']);

        if ($row === null) {
            return null;
        }

        $email = isset($row->email) && is_string($row->email) && $row->email !== '' ? $row->email : null;
        $name = isset($row->name) && is_string($row->name) && $row->name !== '' ? $row->name : null;

        return new Recipient($userId, $email, $name, $this->defaultLocale);
    }
}
