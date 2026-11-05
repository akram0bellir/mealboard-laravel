<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The JSON API's login: "Authorization: <token>" (or "Bearer <token>") from POST /api/auth/login, checked against
 * the `auth_tokens` table (same tokens as the PHP API). The signed-in person is kept in $request->attributes 'api_user'.
 * An inactive account is refused.
 */
class ApiToken
{
    public static function tokenOf(Request $request): ?string
    {
        $header = trim((string) $request->header('Authorization', ''));
        if (stripos($header, 'bearer ') === 0) {
            $header = trim(substr($header, 7));
        }
        return $header === '' ? null : $header;
    }

    public static function find(string $token): ?object
    {
        return DB::table('auth_tokens as t')->join('user as u', 'u.id', '=', 't.user_id')
            ->where('t.token_hash', hash('sha256', $token))->whereRaw('t.expires > UTC_TIMESTAMP(3)')
            ->select('u.*')->first();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = self::tokenOf($request);
        $user = $token === null ? null : self::find($token);
        if ($user === null) {
            return response()->json(['status' => 401, 'message' => 'Sign in to continue.'], 401);
        }
        $request->attributes->set('api_user', $user);
        return $next($request);
    }

    public static function role(object $user): string
    {
        return Roles::normalize($user->User_Type);
    }
}
