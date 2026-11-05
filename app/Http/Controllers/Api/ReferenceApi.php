<?php

namespace App\Http\Controllers\Api;

use App\Support\ApiRows;
use App\Support\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The rules the door needs to read: booking rules, menu, meal-time groups, department rules (the last two can be edited by a super admin). */
class ReferenceApi extends ApiBase
{
    private const GROUP_COLUMNS = ['name', 'meal', 'start', 'end', 'ramadan_start', 'ramadan_end'];

    /** GET /api/booking-rules — public: the booking page needs it before login. */
    public function bookingRules(): JsonResponse
    {
        return $this->json(ApiRows::out('booking_rules', DB::table('booking_rules')->first()));
    }

    /** GET /api/menu?day=|from=&to= — public. */
    public function menu(Request $request): JsonResponse
    {
        $query = DB::table('meun');
        $this->dayFilter($request, $query, 'day');
        return $this->json(['items' => ApiRows::many('meun', $query->orderBy('day')->get(), $this->fields($request))]);
    }

    public function mealGroups(Request $request): JsonResponse
    {
        return $this->json(['items' => ApiRows::many('meal_groups', DB::table('meal_groups')->orderBy('meal')->orderBy('start')->get(), $this->fields($request))]);
    }

    public function saveGroup(Request $request, ?string $id = null): JsonResponse
    {
        $this->superOnly($request);
        $data = [];
        foreach (self::GROUP_COLUMNS as $column) {
            if ($request->has($column)) {
                $data[$column] = mb_substr(trim((string) $request->input($column)), 0, $column === 'name' ? 255 : ($column === 'meal' ? 16 : 5));
            }
        }
        if ($id === null) {
            $now = Ids::now();
            $id = Ids::new();
            $data += ['name' => '', 'meal' => 'lunch', 'start' => '', 'end' => '', 'ramadan_start' => '', 'ramadan_end' => ''];
            DB::table('meal_groups')->insert($data + ['id' => $id, 'created' => $now, 'updated' => $now]);
            return $this->json(ApiRows::out('meal_groups', DB::table('meal_groups')->where('id', $id)->first()), 201);
        }
        if (!DB::table('meal_groups')->where('id', $id)->exists()) {
            $this->fail(404, 'This record was not found.');
        }
        if ($data) {
            DB::table('meal_groups')->where('id', $id)->update($data + ['updated' => Ids::now()]);
        }
        return $this->json(ApiRows::out('meal_groups', DB::table('meal_groups')->where('id', $id)->first()));
    }

    public function deleteGroup(Request $request, string $id): JsonResponse
    {
        $this->superOnly($request);
        if (!DB::table('meal_groups')->where('id', $id)->exists()) {
            $this->fail(404, 'This record was not found.');
        }
        DB::table('meal_groups')->where('id', $id)->delete();
        return $this->json(['ok' => true]);
    }

    public function departmentRules(Request $request): JsonResponse
    {
        return $this->json(['items' => ApiRows::many('department_rules', DB::table('department_rules')->orderBy('department')->get(), $this->fields($request))]);
    }

    /** PUT /api/department-rules/{department} — updates that department's row, else creates it (lunch on). */
    public function saveDepartment(Request $request, string $department): JsonResponse
    {
        $this->superOnly($request);
        $department = trim($department);
        if ($department === '') {
            $this->fail(400, 'Pick a department.');
        }
        $data = [];
        foreach (['breakefast', 'lunch', 'dinner'] as $column) {
            if ($request->has($column)) {
                $value = $request->input($column);
                $data[$column] = in_array(is_string($value) ? strtolower(trim($value)) : $value, [true, 1, '1', 'true', 'yes', 'on'], true) ? 1 : 0;
            }
        }
        foreach (['breakfast_group', 'lunch_group', 'dinner_group'] as $column) {
            if ($request->has($column)) {
                $group = trim((string) $request->input($column));
                $data[$column] = $group !== '' && DB::table('meal_groups')->where('id', $group)->exists() ? $group : null;
            }
        }
        $existing = DB::table('department_rules')->where('department', $department)->first();
        if ($existing) {
            if ($data) {
                DB::table('department_rules')->where('id', $existing->id)->update($data + ['updated' => Ids::now()]);
            }
            return $this->json(ApiRows::out('department_rules', DB::table('department_rules')->where('id', $existing->id)->first()));
        }
        $now = Ids::now();
        $id = Ids::new();
        DB::table('department_rules')->insert($data + ['breakefast' => 0, 'lunch' => 1, 'dinner' => 0, 'breakfast_group' => null, 'lunch_group' => null, 'dinner_group' => null, 'department' => $department, 'id' => $id, 'created' => $now, 'updated' => $now]);
        return $this->json(ApiRows::out('department_rules', DB::table('department_rules')->where('id', $id)->first()), 201);
    }

    private function superOnly(Request $request): void
    {
        if (!$this->isSuper($this->me($request))) {
            $this->fail(403, 'You are not allowed to do this.');
        }
    }
}
