<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which admin pages each role may open: admins and the super admin open everything (Settings is checked
 * by the page itself); a manager runs the menu and follows feedback and numbers.
 */
class AdminPage
{
    /** Pages a manager may open (and everything under them). */
    public const MANAGER_PAGES = ['admin', 'meals', 'reports', 'statistics'];

    public function handle(Request $request, Closure $next): Response
    {
        if (Roles::normalize($request->user()?->User_Type) === 'manager') {
            $first = explode('/', trim($request->path(), '/'))[0];
            if (!in_array($first, self::MANAGER_PAGES, true)) {
                return response()->view('admin.not-for-role', [], 403);
            }
        }
        return $next($request);
    }

    public static function canOpen(?string $userType, string $href): bool
    {
        if (Roles::normalize($userType) !== 'manager') {
            return true;
        }
        return in_array(explode('/', trim($href, '/'))[0], self::MANAGER_PAGES, true);
    }
}
