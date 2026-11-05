<?php

namespace App\Services;

/**
 * The tripod gate: a relay on a COM port of the PC running this site (Windows). Two kinds of message, picked on
 * the Serve page ("Find relay") and saved in the settings table:
 *  - "text" (default): the canteen's Arduino opens on "1" (relay on for 1 s, answers "is high" …).
 *  - "bytes": LCUS relay boards: [A0, n, 1, sum] on, 0.5 s, [A0, n, 0, sum] off.
 * An Arduino restarts whenever its port is opened and ignores what it gets for ~2 s, so opening the gate waits
 * 3 s. Only one program can hold a COM port: close the door app / Python controller first.
 */
class GateRelay
{
    private const BAUD = 9600;
    private const PULSE_MS = 500;
    private const BOOT_MS = 3000;
    private const REPLY_MS = 400;

    private const DEFAULT = ['port' => '', 'relay' => 1, 'message' => 'text', 'text' => '1', 'ending' => ''];

    /** USB ids of boards seen driving gate relays (vendor:product, lower case; '*' = any product). */
    private const KNOWN = [
        '2341:*' => 'Arduino', '2a03:*' => 'Arduino', '1a86:7523' => 'CH340 relay', '1a86:5523' => 'CH341 relay',
        '0403:6001' => 'FTDI serial', '10c4:ea60' => 'CP210x serial',
    ];

    public static function config(?array $raw = null): array
    {
        $c = $raw ?? Settings::get('gate_relay');
        $relay = (int) ($c['relay'] ?? 1);
        return [
            'port' => is_string($c['port'] ?? null) && preg_match('/^COM\d{1,3}$/i', $c['port']) ? strtoupper($c['port']) : '',
            'relay' => $relay >= 1 && $relay <= 8 ? $relay : 1,
            'message' => ($c['message'] ?? '') === 'bytes' ? 'bytes' : 'text',
            'text' => is_string($c['text'] ?? null) ? mb_substr($c['text'], 0, 32) : self::DEFAULT['text'],
            'ending' => in_array($c['ending'] ?? '', ["\n", "\r\n"], true) ? $c['ending'] : '',
        ];
    }

    public static function save(array $raw): array
    {
        Settings::put('gate_relay', self::config($raw));
        return self::config();
    }

    public static function supported(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /** Every COM port Windows sees, known relay boards first. @throws \RuntimeException */
    public static function ports(?array $config = null): array
    {
        if (!self::supported()) {
            throw new \RuntimeException('Gate relays are only supported on a Windows PC.');
        }
        $config ??= self::config();
        $cmd = 'powershell -NoProfile -NonInteractive -Command "Get-CimInstance Win32_PnPEntity | Where-Object { $_.Name -match \'\(COM\d+\)\' } | Select-Object Name,PNPDeviceID | ConvertTo-Json -Compress"';
        $json = trim((string) shell_exec($cmd));
        $items = $json === '' ? [] : (json_decode($json, true) ?? []);
        if ($items && isset($items['Name'])) {
            $items = [$items]; // a single port comes back as an object
        }
        $rows = [];
        foreach ($items as $item) {
            if (!preg_match('/\((COM\d+)\)/', (string) $item['Name'], $m)) {
                continue;
            }
            preg_match('/VID_([0-9A-F]{4})&PID_([0-9A-F]{4})/i', (string) ($item['PNPDeviceID'] ?? ''), $id);
            $vendor = strtolower($id[1] ?? '');
            $product = strtolower($id[2] ?? '');
            $rows[] = [
                'path' => strtoupper($m[1]),
                'name' => (string) $item['Name'],
                'vendorId' => $vendor,
                'productId' => $product,
                'kind' => self::KNOWN["{$vendor}:{$product}"] ?? self::KNOWN["{$vendor}:*"] ?? '',
                'selected' => $config['port'] === strtoupper($m[1]),
            ];
        }
        // Nothing saved yet: the gate uses the first known board, so show that one as in use.
        if ($config['port'] === '') {
            foreach ($rows as &$r) {
                if ($r['kind'] !== '') {
                    $r['selected'] = true;
                    break;
                }
            }
            unset($r);
        }
        usort($rows, fn ($a, $b) => [(int) $b['selected'], (int) ($b['kind'] !== ''), $a['path']] <=> [(int) $a['selected'], (int) ($a['kind'] !== ''), $b['path']]);
        return $rows;
    }

    /** Sends the open message on a COM port and returns what the board answered. Never throws. */
    public static function pulse(string $port, array $config): array
    {
        if (!self::supported()) {
            return ['ok' => false, 'error' => 'Gate relays are only supported on a Windows PC.'];
        }
        if (!preg_match('/^COM\d{1,3}$/i', $port)) {
            return ['ok' => false, 'error' => 'Not a COM port: ' . $port];
        }
        $port = strtoupper($port);
        $config = self::config($config);
        $handle = null;
        try {
            // dtr/rts off: some boards then don't restart when the port is opened.
            exec("mode {$port}: BAUD=" . self::BAUD . ' PARITY=N data=8 stop=1 xon=off odsr=off octs=off dtr=off rts=off idsr=off 2>&1', $out, $code);
            $handle = @fopen('\\\\.\\' . $port, 'r+b');
            if ($handle === false) {
                return ['ok' => false, 'error' => "Cannot open {$port}. Is it plugged in, and not used by another program (door app, Python controller)?"];
            }
            stream_set_blocking($handle, false);
            usleep(self::BOOT_MS * 1000);
            fread($handle, 4096); // what the board said while starting up
            if ($config['message'] === 'text') {
                self::write($handle, $config['text'] . $config['ending']);
            } else {
                self::write($handle, self::relayCommand($config['relay'], true));
                usleep(self::PULSE_MS * 1000);
                self::write($handle, self::relayCommand($config['relay'], false));
            }
            usleep(self::REPLY_MS * 1000);
            return ['ok' => true, 'reply' => self::describe((string) fread($handle, 4096))];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /** Pulses the saved relay once (the saved port, else the first known board). Never throws. */
    public static function open(): array
    {
        $config = self::config();
        $port = $config['port'];
        if ($port === '') {
            try {
                foreach (self::ports($config) as $p) {
                    if ($p['kind'] !== '') {
                        $port = $p['path'];
                        break;
                    }
                }
            } catch (\Throwable) {
            }
        }
        if ($port === '') {
            return ['ok' => false, 'error' => 'No relay COM port found — is it plugged in? Use "Find relay" on the Serve page to pick it.'];
        }
        return self::pulse($port, $config);
    }

    /** [A0, channel, on/off, checksum] — the LCUS relay protocol. */
    private static function relayCommand(int $relay, bool $on): string
    {
        $state = $on ? 1 : 0;
        return chr(0xA0) . chr($relay) . chr($state) . chr((0xA0 + $relay + $state) & 0xFF);
    }

    private static function write($handle, string $data): void
    {
        if (@fwrite($handle, $data) === false) {
            throw new \RuntimeException('Cannot write to the relay.');
        }
        fflush($handle);
    }

    /** The board's answer, readable: its text, or hex bytes when it isn't text. */
    private static function describe(string $data): string
    {
        if ($data === '') {
            return '';
        }
        return preg_match('/^[\x20-\x7e\r\n\t]*$/', $data)
            ? trim($data)
            : implode(' ', array_map(fn ($b) => strtoupper(str_pad(dechex(ord($b)), 2, '0', STR_PAD_LEFT)), str_split($data)));
    }
}
