<?php

namespace App\Http\Controllers\Api;

use App\Services\Standing;
use App\Support\ApiRows;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What the desktop door app reads and writes: people, the day's bookings (served / missed flags), badge numbers,
 * and its heartbeat. Same URLs, rules and answers as the PHP API.
 */
class DoorApi extends ApiBase
{
    private const MEALS = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];

    private const SERVED = ['attended_breakfast', 'attended_lunch', 'attended_dinner', 'no_show_breakfast', 'no_show_lunch', 'no_show_dinner'];

    /* ------------------------------------------------------------------ users */

    public function users(Request $request): JsonResponse
    {
        $me = $this->me($request);
        if (!$this->isStaff($me) && !$this->isDoor($me)) {
            return $this->json(['items' => ApiRows::many('user', [$me], $this->fields($request))]); // an employee sees only their own row
        }
        $rows = DB::table('user')->orderBy('firstname')->orderBy('lastname')->get();
        return $this->json(['items' => ApiRows::many('user', $rows, $this->fields($request))]);
    }

    public function user(Request $request, string $id): JsonResponse
    {
        $me = $this->me($request);
        $row = DB::table('user')->where('id', $id)->first();
        if ($row === null) {
            $this->fail(404, 'This user was not found.');
        }
        if ($row->id !== $me->id && !$this->isStaff($me) && !$this->isDoor($me)) {
            $this->fail(403, 'You are not allowed to do this.');
        }
        return $this->json(ApiRows::out('user', $row, $this->fields($request)));
    }

    public function search(Request $request): JsonResponse
    {
        $me = $this->me($request);
        if (!$this->isStaff($me) && !$this->isDoor($me)) {
            $this->fail(403, 'You are not allowed to do this.');
        }
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return $this->json(['items' => []]);
        }
        $like = '%' . $q . '%';
        $rows = DB::table('user')->where(fn ($w) => $w->where('firstname', 'like', $like)->orWhere('lastname', 'like', $like)->orWhere('email', 'like', $like)->orWhere('username', 'like', $like))
            ->orderBy('firstname')->orderBy('lastname')->limit(8)->get();
        return $this->json(['items' => ApiRows::many('user', $rows, $this->fields($request))]);
    }

    /** GET /api/users/by-badge?card_id= | ?id_user= — null when nobody has that number. */
    public function byBadge(Request $request): JsonResponse
    {
        $me = $this->me($request);
        if (!$this->isStaff($me) && !$this->isDoor($me)) {
            $this->fail(403, 'You are not allowed to do this.');
        }
        $card = trim((string) $request->query('card_id', ''));
        $employee = trim((string) $request->query('id_user', ''));
        if ($card === '' && $employee === '') {
            $this->fail(400, 'Give a badge number or an employee number.');
        }
        $row = DB::table('user')->where($card !== '' ? 'card_id' : 'id_user', (int) ($card !== '' ? $card : $employee))->orderBy('firstname')->first();
        return $this->json(ApiRows::out('user', $row, $this->fields($request)));
    }

    /** PATCH /api/users/{id} — the door (and an admin) may only link a badge number. */
    public function updateUser(Request $request, string $id): JsonResponse
    {
        $me = $this->me($request);
        $target = DB::table('user')->where('id', $id)->first();
        if ($target === null) {
            $this->fail(404, 'This user was not found.');
        }
        if (!$this->isDoor($me) && !$this->isAdmin($me)) {
            $this->fail(403, 'You can only change your own password.');
        }
        foreach (array_keys($request->all()) as $column) {
            if ($column !== 'card_id') {
                $this->fail(403, $this->isDoor($me) ? 'The door can only link badges.' : 'Other user changes are made on the Users page.');
            }
        }
        if ($request->has('card_id')) {
            $value = $request->input('card_id');
            DB::table('user')->where('id', $id)->update(['card_id' => $value === null || $value === '' ? null : (int) round((float) $value), 'updated' => Ids::now()]);
        }
        return $this->json(ApiRows::out('user', DB::table('user')->where('id', $id)->first()));
    }

    /* ----------------------------------------------------------------- orders */

    /** GET /api/orders?day=|from=&to=|since=&meal=&notMissed=1&user=&page=&perPage=&fields=&expand=user */
    public function orders(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $staffView = $this->isStaff($me) || $this->isDoor($me);
        $user = trim((string) $request->query('user', ''));
        if ($user === '') {
            $user = $staffView ? '' : $me->id;
        } elseif ($user !== $me->id && !$staffView) {
            $this->fail(403, 'You are not allowed to do this.');
        }

        $query = DB::table('oder');
        if ($user !== '') {
            $query->where('user', $user);
        }
        $since = trim((string) $request->query('since', ''));
        if ($since !== '') {
            if (!$this->isDayKey($since)) {
                $this->fail(400, 'The "since" day must be YYYY-MM-DD.');
            }
            $query->where('date', '>=', "$since 00:00:00.000");
        }
        $this->dayFilter($request, $query, 'date');
        $meal = $this->meal((string) $request->query('meal', ''));
        if ($meal !== null) {
            $query->where(self::MEALS[$meal], 1);
            if ($request->query('notMissed') === '1') {
                $query->where("no_show_$meal", 0);
            }
        }

        $fields = $this->fields($request);
        if ($since !== '' || $request->has('page') || $request->has('perPage')) {
            $page = max(1, (int) $request->query('page', 1));
            $perPage = max(1, min(5000, (int) $request->query('perPage', 1000)));
            $total = (clone $query)->count();
            $rows = $query->orderBy('date')->orderBy('user')->forPage($page, $perPage)->get();
            return $this->json(['items' => ApiRows::many('oder', $rows, $fields), 'page' => $page, 'totalPages' => max(1, (int) ceil($total / $perPage))]);
        }

        $items = ApiRows::many('oder', $query->orderBy('date')->get(), $fields);
        if (in_array('user', array_map('trim', explode(',', (string) $request->query('expand', ''))), true)) {
            $users = DB::table('user')->whereIn('id', array_unique(array_column($items, 'user')))->get()->keyBy('id');
            foreach ($items as &$item) {
                $item['expand'] = ['user' => ApiRows::out('user', $users[$item['user'] ?? ''] ?? null)];
            }
        }
        return $this->json(['items' => $items]);
    }

    public function firstMissed(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $user = trim((string) $request->query('user', '')) ?: $me->id;
        if ($user !== $me->id && !$this->isStaff($me)) {
            $this->fail(403, 'You are not allowed to do this.');
        }
        $since = trim((string) $request->query('since', ''));
        if ($since !== '' && !$this->isDayKey($since)) {
            $this->fail(400, 'The "since" day must be YYYY-MM-DD.');
        }
        $query = DB::table('oder')->where('user', $user)->where(function ($w) {
            foreach (self::MEALS as $meal => $column) {
                $w->orWhere(fn ($m) => $m->where($column, 1)->where("no_show_$meal", 1));
            }
        });
        if ($since !== '') {
            $query->where('date', '>=', "$since 00:00:00.000");
        }
        return $this->json(ApiRows::out('oder', $query->orderBy('date')->first(), $this->fields($request)));
    }

    public function order(Request $request, string $id): JsonResponse
    {
        $me = $this->me($request);
        $row = DB::table('oder')->where('id', $id)->first();
        if ($row === null) {
            $this->fail(404, 'This booking was not found.');
        }
        if ($row->user !== $me->id && !$this->isStaff($me) && !$this->isDoor($me)) {
            $this->fail(403, 'You are not allowed to do this.');
        }
        return $this->json(ApiRows::out('oder', $row, $this->fields($request)));
    }

    /**
     * PATCH /api/orders/{id} — the door (and an admin) mark meals as served or missed. A meal that becomes
     * "booked and missed" adds a strike; taking the mark back takes the strike back.
     */
    public function updateOrder(Request $request, string $id): JsonResponse
    {
        $me = $this->me($request);
        $row = DB::table('oder')->where('id', $id)->first();
        if ($row === null) {
            $this->fail(404, 'This booking was not found.');
        }
        if (!$this->isDoor($me) && !$this->isAdmin($me)) {
            $this->fail(403, 'You are not allowed to do this.');
        }
        $data = [];
        foreach ($request->all() as $column => $value) {
            if (!in_array($column, self::SERVED, true)) {
                $this->fail(403, $this->isDoor($me) ? 'The door can only mark meals as served or missed.' : 'Bookings are changed on the admin pages.');
            }
            $data[$column] = in_array(is_string($value) ? strtolower(trim($value)) : $value, [true, 1, '1', 'true', 'yes', 'on'], true) ? 1 : 0;
        }
        if ($data) {
            DB::table('oder')->where('id', $id)->update($data + ['updated' => Ids::now()]);
        }
        $fresh = DB::table('oder')->where('id', $id)->first();
        $this->applyNoShowChanges($row, $fresh);
        return $this->json(ApiRows::out('oder', $fresh));
    }

    private function applyNoShowChanges(object $before, object $after): void
    {
        $day = substr((string) $after->date, 0, 10);
        foreach (self::MEALS as $meal => $column) {
            $was = (int) $before->{$column} === 1 && (int) $before->{"no_show_$meal"} === 1;
            $is = (int) $after->{$column} === 1 && (int) $after->{"no_show_$meal"} === 1;
            if (!$was && $is) {
                Standing::addStrike((string) $after->user, $day, $meal, 'missed');
            } elseif ($was && !$is) {
                Standing::takeBackStrike((string) $after->user);
            }
        }
    }

    private function meal(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = $value === 'brakfast' ? 'breakfast' : $value;
        return isset(self::MEALS[$value]) ? $value : null;
    }

    /* -------------------------------------------------------------- heartbeat */

    /** POST /api/mealboard/door-heartbeat — a door PC says it is alive, how many marks it still has to send, and how long it waits after a meal. */
    public function heartbeat(Request $request): JsonResponse
    {
        $me = $this->me($request);
        if (!$this->isDoor($me) && !$this->isAdmin($me)) {
            $this->fail(403, 'You are not allowed to do this.');
        }
        $door = trim((string) $request->input('door', ''));
        if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $door)) {
            $this->fail(400, 'Invalid door id.');
        }
        $pending = max(0, (int) $request->input('pending', 0));
        DB::table('_mealboard_doors')->updateOrInsert(['door' => $door], [
            'name' => mb_substr(is_scalar($request->input('name')) ? (string) $request->input('name') : '', 0, 80),
            'last_seen' => (int) round(microtime(true) * 1000),
            'pending' => $pending,
            'oldest_pending' => $pending > 0 ? (int) $request->input('oldestPendingAt', 0) : null,
            'close_after' => max(0, min(180, (int) $request->input('closeAfterMinutes', 0))),
        ]);
        return $this->json(['ok' => true]);
    }
}
