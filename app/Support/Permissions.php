<?php

namespace App\Support;

/**
 * Global permissions. Deliberately few — per-room moderation is not modelled
 * here because it varies by room and lives on the membership pivot instead.
 */
final class Permissions
{
    public const MANAGE_USERS = 'users.manage';

    public const IMPORT_USERS = 'users.import';

    public const MANAGE_ROOMS = 'rooms.manage';

    public const BROADCAST_EMERGENCY = 'emergency.broadcast';

    public const VIEW_EMERGENCY_LOG = 'emergency.log.view';

    public const MANAGE_SETTINGS = 'settings.manage';

    /**
     * Posting a form, and reading what people answered.
     *
     * Its own permission rather than a reuse of MANAGE_ROOMS: a form collects
     * named answers from staff, which is a different kind of authority from
     * renaming a channel, and one an installation may want to hand out
     * separately.
     */
    public const MANAGE_FORMS = 'forms.manage';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MANAGE_USERS,
            self::IMPORT_USERS,
            self::MANAGE_ROOMS,
            self::BROADCAST_EMERGENCY,
            self::VIEW_EMERGENCY_LOG,
            self::MANAGE_SETTINGS,
            self::MANAGE_FORMS,
        ];
    }

    public const ROLE_ADMIN = 'admin';

    public const ROLE_EMPLOYEE = 'employee';

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::ROLE_ADMIN, self::ROLE_EMPLOYEE];
    }
}
