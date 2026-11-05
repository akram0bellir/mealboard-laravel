<?php

namespace App\Support;

/**
 * The User_Type roles (same rules as the PHP API's lib/auth.php).
 *
 *   employee / responsible / intern  the employee pages
 *   manager                          admin side, but not an admin
 *   admin / super_admin              the whole admin side
 *   door                             the desktop door app only
 */
class Roles
{
    /** The data has old spellings; anything unknown is an employee. */
    public static function normalize(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'responsible' => 'responsible',
            'intern' => 'intern',
            'manager', 'manger', 'meal_manager' => 'manager',
            'admin', 'admain' => 'admin',
            'super_admin', 'super admin', 'super_admain', 'superadmin' => 'super_admin',
            'door' => 'door',
            default => 'employee',
        };
    }

    public static function isDoor(?string $value): bool
    {
        return self::normalize($value) === 'door';
    }

    /** admin, super_admin and manager open the admin side. */
    public static function opensAdminSide(?string $value): bool
    {
        return in_array(self::normalize($value), ['admin', 'super_admin', 'manager'], true);
    }

    public static function isAdmin(?string $value): bool
    {
        return in_array(self::normalize($value), ['admin', 'super_admin'], true);
    }

    public static function isSuper(?string $value): bool
    {
        return self::normalize($value) === 'super_admin';
    }

    /** Roles only a super admin may give or take away. */
    public static function isProtected(?string $value): bool
    {
        return in_array(self::normalize($value), ['manager', 'admin', 'super_admin', 'door'], true);
    }

    /** Where this account lands after signing in. */
    public static function home(?string $value): string
    {
        return self::opensAdminSide($value) ? '/admin' : '/week';
    }
}
