<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\Role;

/**
 * Fine-grained permissions. Authorization checks are expressed in terms of
 * permissions (not roles) so policies stay stable as the role→permission
 * mapping evolves.
 */
enum Permission: string
{
    case ManageOwnProfile = 'profile.manage';
    case ViewUsers = 'users.view';
    case ManageUsers = 'users.manage';
    case ModerateContent = 'content.moderate';
    case ViewAuditLogs = 'audit.view';
    case ManageRoles = 'roles.manage';
}
