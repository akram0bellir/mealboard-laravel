<?php

namespace Tests\Unit;

use App\Services\Backup;
use App\Services\DayOrders;
use App\Services\GateRelay;
use App\Services\Mailer;
use App\Services\Notifier;
use App\Services\Settings;
use App\Support\ApiRows;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    /* ---- e-mail ---- */

    public function test_the_master_switch_turns_all_sending_off(): void
    {
        $this->assertFalse(config('mealboard.send_mail'));
        $this->assertFalse(Mailer::isOn());
        $this->assertSame([0, 0], Mailer::flushQueue());
        $this->expectException(\RuntimeException::class);
        Mailer::send('someone@test.local', 'Hi', 'Hello');
    }

    public function test_address_lists_are_cleaned_and_checked(): void
    {
        $this->assertSame(['a@x.com', 'b@y.com'], DayOrders::addresses("A@X.com, b@y.com;\n a@x.com"));
        foreach (['', 'not-an-address', 'ok@x.com, nope'] as $bad) {
            try {
                DayOrders::addresses($bad);
                $this->fail("accepted: [$bad]");
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectExceptionMessage('At most 20');
        DayOrders::addresses(implode(',', array_map(fn ($i) => "p{$i}@x.com", range(1, 21))));
    }

    public function test_the_order_list_counts_and_csv(): void
    {
        $a = $this->person(['firstname' => 'Amel', 'lastname' => 'Zed', 'Department' => '2']);
        $b = $this->person(['firstname' => 'Bilal', 'lastname' => 'Alpha', 'Department' => '2']);
        $c = $this->person(['firstname' => 'Chaima', 'lastname' => 'First', 'Department' => '1']);
        $this->order($a, '2031-03-03', ['lunch'], ['attended_lunch' => 1]);
        $this->order($b, '2031-03-03', ['lunch', 'dinner'], ['no_show_lunch' => 1]);
        $this->order($c, '2031-03-03', ['breakfast']);
        $this->order($this->person(), '2031-03-03', []); // a row with no meal is not a booking
        [$lines, $totals] = DayOrders::data('2031-03-03');
        $this->assertCount(3, $lines);
        $this->assertSame(['breakfast' => 1, 'lunch' => 2, 'dinner' => 1], $totals);
        $this->assertSame(['Chaima First', 'Amel Zed', 'Bilal Alpha'], array_column($lines, 'name'), 'sorted by department, then name');
        $csv = DayOrders::csv('2031-03-03', $lines);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM so Excel opens it as UTF-8');
        $this->assertStringContainsString('"2031-03-03","Amel Zed"', $csv);
        $this->assertStringContainsString('"served"', $csv);
        $this->assertStringContainsString('"missed"', $csv);
        $this->assertStringContainsString("\r\n", $csv);
    }

    public function test_notifications_queue_e_mails_only_for_active_people_with_an_address(): void
    {
        $ok = $this->person();
        $off = $this->person(['Status' => 0]);
        $none = $this->person(['email' => null]);
        Notifier::send([$ok->id, $off->id, $none->id], 'Hello  there', 'Body', 'Test');
        $queued = DB::table('pending_emails')->whereIn('to_address', [$ok->email, $off->email])->pluck('to_address')->all();
        $this->assertSame([$ok->email], $queued);
        $this->assertSame('Hello there', DB::table('pending_emails')->where('to_address', $ok->email)->value('subject'), 'spaces are tidied');
        $this->assertSame(1, DB::table('notifications')->where('title', 'Hello  there')->count(), 'but everyone gets the in-app notification');
        $this->assertSame('', Notifier::send([], 'x', 'y', 'z'), 'no recipients, nothing sent');
    }

    /* ---- gate ---- */

    public function test_the_gate_config_is_cleaned(): void
    {
        $c = GateRelay::config(['port' => 'com4', 'relay' => 3, 'message' => 'bytes', 'text' => str_repeat('x', 50), 'ending' => "\r\n"]);
        $this->assertSame('COM4', $c['port']);
        $this->assertSame(3, $c['relay']);
        $this->assertSame('bytes', $c['message']);
        $this->assertSame(32, strlen($c['text']));
        $this->assertSame("\r\n", $c['ending']);
        $bad = GateRelay::config(['port' => 'COM4; calc', 'relay' => 99, 'message' => 'x', 'ending' => 'zzz']);
        $this->assertSame('', $bad['port'], 'a port name that is not COMn is dropped');
        $this->assertSame(1, $bad['relay']);
        $this->assertSame('text', $bad['message']);
        $this->assertSame('', $bad['ending']);
    }

    public function test_the_gate_never_runs_a_command_on_a_bad_port_name(): void
    {
        $r = GateRelay::pulse('COM1 & calc', GateRelay::config());
        $this->assertFalse($r['ok']);
        $r = GateRelay::pulse('COM98', GateRelay::config());
        $this->assertFalse($r['ok'], 'a port that does not exist');
    }

    public function test_the_gate_settings_are_saved(): void
    {
        $saved = GateRelay::save(['port' => 'COM7', 'message' => 'text', 'text' => '1', 'ending' => "\n"]);
        $this->assertSame('COM7', $saved['port']);
        $this->assertSame('COM7', Settings::get('gate_relay')['port']);
    }

    /* ---- api rows ---- */

    public function test_api_rows_have_the_old_api_shapes(): void
    {
        $row = ApiRows::out('user', (object) ['id' => 'a', 'Status' => 1, 'sex' => 0, 'card_id' => '123', 'created' => '2026-10-14 20:14:26.7', 'password' => 'secret', 'tokenKey' => 'k', 'firstname' => 'Ana', 'period_start' => null]);
        $this->assertTrue($row['Status']);
        $this->assertFalse($row['sex']);
        $this->assertSame(123, $row['card_id']);
        $this->assertSame('2026-10-14 20:14:26.700Z', $row['created']);
        $this->assertSame('', $row['period_start'], 'NULL dates are empty strings');
        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('tokenKey', $row);
        $this->assertSame(['id' => 'a'], ApiRows::out('user', (object) ['id' => 'a', 'firstname' => 'Ana'], ['id']));
        $this->assertNull(ApiRows::out('user', null));
    }

    /* ---- backup ---- */

    public function test_a_backup_can_be_read_back(): void
    {
        $p = $this->person(['firstname' => 'Backupé']);
        $dir = sys_get_temp_dir() . '/mb-backup-' . uniqid();
        mkdir($dir);
        $file = $dir . '/dump.sql.gz';
        $size = Backup::dumpDatabase($file);
        $this->assertGreaterThan(1000, $size);
        $sql = '';
        $gz = gzopen($file, 'rb');
        while (!gzeof($gz)) {
            $sql .= gzread($gz, 65536);
        }
        gzclose($gz);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `user`', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `oder`', $sql);
        $this->assertStringContainsString($p->email, $sql, 'rows are in the dump');
        $this->assertStringContainsString('Backupé', $sql, 'accents survive');
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}
