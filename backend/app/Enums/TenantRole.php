<?php

namespace App\Enums;

enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /**
     * Roles permitted to manage members and perform destructive actions.
     */
    public function canManageTenant(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
