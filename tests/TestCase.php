<?php

namespace Tests;

use App\Models\User;
use App\Services\BookingRules;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Every test runs on mealboard_test (a clone of the copy database) inside a transaction that is rolled back, with
 * the clock fixed on Thursday 2026-11-05 10:00 (canteen time) unless the test moves it.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Never run on the real data.
        $this->assertSame('mealboard_test', DB::connection()->getDatabaseName(), 'The tests must run on mealboard_test.');
        $this->at('2026-11-05 10:00');
        $this->rules(['max_no_show' => 3, 'period_days' => 30, 'cancel_deadline_hour' => 14, 'cancel_deadline_days_before' => 1, 'late_cancel_counts' => 0, 'ramadan_mode' => 0, 'auto_no_show_from' => '']);
    }

    /** Moves the clock (canteen time). */
    protected function at(string $dateTime): void
    {
        $this->travelTo(CarbonImmutable::parse($dateTime, config('app.timezone')));
        $this->forgetRules();
    }

    /** Changes the booking rules row and makes the code read it again. */
    protected function rules(array $columns): void
    {
        DB::table('booking_rules')->update($columns);
        $this->forgetRules();
    }

    protected function forgetRules(): void
    {
        $property = new \ReflectionProperty(BookingRules::class, 'current');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    /** A new active employee (a copy of an existing row, with its own e-mail, no badge). */
    protected function person(array $over = []): User
    {
        static $n = 0;
        $n++;
        $row = (array) DB::table('user')->where('email', 'demo2@demo.mealboard.test')->first();
        $id = Ids::new();
        $row = array_merge($row, [
            'id' => $id,
            'email' => "t{$n}-{$id}@test.local",
            'username' => "t{$n}{$id}",
            'tokenKey' => bin2hex(random_bytes(15)),
            'password' => password_hash('test12345', PASSWORD_BCRYPT),
            'firstname' => 'Test', 'lastname' => 'Person' . $n,
            'User_Type' => 'employee', 'Department' => '1', 'Position' => 'tester',
            'Breakfast' => 1, 'Lunch' => 1, 'Dinner' => 1, 'multiple_meals' => 0,
            'Strikes' => 0, 'period_start' => null, 'blocked_until' => null, 'Status' => 1,
            'card_id' => null, 'id_user' => null, 'sex' => 0,
            'breakfast_group' => null, 'lunch_group' => null, 'dinner_group' => null,
            'created' => Ids::now(), 'updated' => Ids::now(),
        ], $over);
        DB::table('user')->insert($row);
        return User::find($id);
    }

    /** A booking row; $meals = ['lunch', ...]; $flags = columns like 'attended_lunch' => 1. Returns the order id. */
    protected function order(User|string $user, string $date, array $meals, array $flags = []): string
    {
        $id = Ids::new();
        $column = ['breakfast' => 'bool', 'lunch' => 'bool2', 'dinner' => 'bool3'];
        $row = ['id' => $id, 'user' => $user instanceof User ? $user->id : $user, 'food' => null, 'bool' => 0, 'bool2' => 0, 'bool3' => 0, 'date' => $date . ' 12:00:00.000',
            'attended_breakfast' => 0, 'attended_lunch' => 0, 'attended_dinner' => 0, 'no_show_breakfast' => 0, 'no_show_lunch' => 0, 'no_show_dinner' => 0,
            'day_group' => null, 'switched_by' => null, 'created' => Ids::now(), 'updated' => Ids::now()];
        foreach ($meals as $meal) {
            $row[$column[$meal]] = 1;
        }
        DB::table('oder')->insert(array_merge($row, $flags));
        return $id;
    }

    protected function strikes(User|string $user): int
    {
        return (int) DB::table('user')->where('id', $user instanceof User ? $user->id : $user)->value('Strikes');
    }

    /** The booked meals of a person on a day. */
    protected function meals(User|string $user, string $date): array
    {
        $row = DB::table('oder')->where('user', $user instanceof User ? $user->id : $user)->where('date', '>=', "$date 00:00:00.000")->where('date', '<=', "$date 23:59:59.999")->first();
        if (!$row) {
            return [];
        }
        return array_keys(array_filter(['breakfast' => $row->bool, 'lunch' => $row->bool2, 'dinner' => $row->bool3], fn ($v) => (int) $v === 1));
    }

    protected function admin(string $type = 'admin'): User
    {
        return $this->person(['User_Type' => $type, 'Department' => '']);
    }
}
