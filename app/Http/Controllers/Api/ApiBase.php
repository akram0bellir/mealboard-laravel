<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Roles;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Shared helpers of the JSON API controllers (the door app, the gate and the cron address). */
abstract class ApiBase extends Controller
{
    /** Stops the request with a readable JSON message, like the PHP API: {"status": 403, "message": "..."}. */
    protected function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['status' => $status, 'message' => $message], $status));
    }

    protected function me(Request $request): object
    {
        return $request->attributes->get('api_user');
    }

    protected function isDoor(object $user): bool
    {
        return Roles::isDoor($user->User_Type);
    }

    /** admin and super admin (a manager is not an admin). */
    protected function isAdmin(object $user): bool
    {
        return Roles::isAdmin($user->User_Type);
    }

    protected function isSuper(object $user): bool
    {
        return Roles::isSuper($user->User_Type);
    }

    /** admin side: admin, super admin, manager. */
    protected function isStaff(object $user): bool
    {
        return Roles::opensAdminSide($user->User_Type);
    }

    /** `?fields=a,b,c` -> ['a','b','c'] or null. */
    protected function fields(Request $request): ?array
    {
        $fields = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('fields', '')))));
        return $fields ?: null;
    }

    protected function isDayKey(?string $value): bool
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** "day" / "from" / "to" query values -> where clauses on a datetime column. */
    protected function dayFilter(Request $request, $query, string $column): void
    {
        $day = trim((string) $request->query('day', ''));
        if ($day !== '') {
            if (!$this->isDayKey($day)) {
                $this->fail(400, 'Pick a valid day (YYYY-MM-DD).');
            }
            $query->where($column, '>=', "$day 00:00:00.000")->where($column, '<=', "$day 23:59:59.999");
            return;
        }
        foreach (['from' => ['>=', '00:00:00.000'], 'to' => ['<=', '23:59:59.999']] as $name => [$op, $time]) {
            $value = trim((string) $request->query($name, ''));
            if ($value === '') {
                continue;
            }
            if (!$this->isDayKey($value)) {
                $this->fail(400, "The \"{$name}\" day must be YYYY-MM-DD.");
            }
            $query->where($column, $op, "$value $time");
        }
    }

    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        if ($data === null) {
            // "no such person" is the JSON value null, like the PHP API (Laravel would send {}).
            return JsonResponse::fromJsonString('null', $status, ['Cache-Control' => 'no-store']);
        }
        return response()->json($data, $status, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
