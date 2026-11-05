<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\ApiToken;
use App\Services\LoginThrottle;
use App\Support\ApiRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** POST /api/auth/login | refresh | logout — the door app signs in with its door account and keeps the token. */
class AuthApi extends ApiBase
{
    public function login(Request $request): JsonResponse
    {
        // One box: an e-mail address or a username ("identity" / "username" are accepted too).
        $login = trim((string) ($request->input('email') ?? $request->input('identity') ?? $request->input('username') ?? ''));
        $password = (string) $request->input('password', '');
        $key = LoginThrottle::key($login, $request->ip());
        if (LoginThrottle::isBlocked($key)) {
            $this->fail(429, 'Too many failed attempts. Try again in a few minutes.');
        }

        $user = null;
        if ($login !== '') {
            $column = str_contains($login, '@') ? 'email' : 'username';
            $user = DB::table('user')->whereRaw("LOWER({$column}) = ?", [mb_strtolower($login)])->first();
        }
        // password_verify, not Hash::check: old hashes start with $2a$.
        if ($user === null || $password === '' || !password_verify($password, (string) $user->password)) {
            LoginThrottle::recordFailure($key);
            $this->fail(400, 'Failed to authenticate.');
        }
        LoginThrottle::clear($key);
        if ((int) $user->Status !== 1) {
            $this->fail(400, 'This account is deactivated.');
        }

        $token = bin2hex(random_bytes(32));
        DB::table('auth_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => $user->id,
            'created' => LoginThrottle::now(),
            'expires' => $this->expiry(),
            'device' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
        return $this->json(['token' => $token, 'record' => ApiRows::out('user', $user)]);
    }

    /** Keeps the session going: the same token, a later expiry. 401 when the token is unknown or has ended. */
    public function refresh(Request $request): JsonResponse
    {
        $token = ApiToken::tokenOf($request);
        $user = $token === null ? null : ApiToken::find($token);
        if ($user === null) {
            $this->fail(401, 'Your session has expired: sign in again.');
        }
        DB::table('auth_tokens')->where('token_hash', hash('sha256', $token))->update(['expires' => $this->expiry()]);
        return $this->json(['token' => $token, 'record' => ApiRows::out('user', $user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = ApiToken::tokenOf($request);
        if ($token !== null) {
            DB::table('auth_tokens')->where('token_hash', hash('sha256', $token))->delete();
        }
        return $this->json(['ok' => true]);
    }

    private function expiry(): string
    {
        return gmdate('Y-m-d H:i:s', time() + (int) config('mealboard.token_days') * 86400) . '.000';
    }
}
