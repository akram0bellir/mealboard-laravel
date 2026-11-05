<?php

namespace App\Services;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * E-mail through the company SMTP account (Settings → SMTP), without extra packages. Port 465 = SSL from the
 * start, any other port = STARTTLS. Same behaviour as the PHP API's lib/mail.php.
 */
class Mailer
{
    private $socket = null;

    private function __construct(private array $settings)
    {
    }

    /** The saved SMTP settings, or null when e-mail is not set up. */
    public static function settings(): ?array
    {
        if (!config('mealboard.send_mail')) {
            return null; // MEALBOARD_SEND_MAIL=false: nothing is ever sent from this copy
        }
        $row = DB::table('smtp')->first();
        $host = trim((string) ($row->SMTP_server_host ?? ''));
        $from = trim((string) ($row->Sender_address ?? ''));
        if ($host === '' || $from === '') {
            return null;
        }
        return [
            'host' => $host,
            'port' => (int) (trim((string) $row->Port) ?: 587),
            'user' => trim((string) $row->Username),
            'pass' => (string) $row->Password,
            'sender_name' => trim((string) $row->Sender_name) ?: 'Mealboard',
            'sender_address' => $from,
        ];
    }

    public static function isOn(): bool
    {
        return self::settings() !== null;
    }

    /**
     * Send one e-mail to one or several addresses.
     * $attachment: ['filename' => ..., 'content' => ..., 'mime' => ...]
     *
     * @throws RuntimeException when the mail server refuses
     */
    public static function send(string|array $to, string $subject, string $text, ?array $attachment = null): void
    {
        $settings = self::settings();
        if ($settings === null) {
            throw new RuntimeException('E-mail is switched off: fill in the SMTP settings first (Settings → SMTP).');
        }
        $recipients = array_values(array_filter(array_map('trim', is_array($to) ? $to : [$to])));
        foreach ($recipients as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("Not an e-mail address: {$address}");
            }
        }
        if ($recipients === []) {
            throw new RuntimeException('Add at least one e-mail address.');
        }
        (new self($settings))->deliver($recipients, $subject, $text, $attachment);
    }

    /** Send up to $limit queued e-mails (notifications). Returns [sent, failed]. */
    public static function flushQueue(int $limit = 50): array
    {
        if (!self::isOn()) {
            return [0, 0];
        }
        $sent = $failed = 0;
        $rows = DB::table('pending_emails')->whereNull('sent_at')->orderBy('created')->limit(max(1, $limit))->get();
        foreach ($rows as $row) {
            try {
                self::send((string) $row->to_address, (string) $row->subject, (string) $row->body);
                DB::table('pending_emails')->where('id', $row->id)->update(['sent_at' => Ids::now(), 'error' => '']);
                $sent++;
            } catch (\Throwable $e) {
                DB::table('pending_emails')->where('id', $row->id)->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ]);
                $failed++;
            }
        }
        return [$sent, $failed];
    }

    private function deliver(array $recipients, string $subject, string $text, ?array $attachment): void
    {
        $s = $this->settings;
        $port = $s['port'];
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client(sprintf('%s://%s:%d', $port === 465 ? 'ssl' : 'tcp', $s['host'], $port), $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException(sprintf('Cannot reach the mail server %s:%d (%s)', $s['host'], $port, $errstr ?: $errno));
        }
        $this->socket = $socket;
        stream_set_timeout($socket, 20);

        try {
            $hello = 'EHLO ' . (gethostname() ?: 'mealboard.local');
            $this->expect([220]);
            $features = $this->command($hello, [250]);
            // A mail server on this very PC may skip TLS (testing); any other must offer it.
            $local = in_array($s['host'], ['127.0.0.1', 'localhost', '::1'], true);
            if ($port !== 465 && !($local && !str_contains($features, 'STARTTLS'))) {
                $this->command('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('The mail server refused STARTTLS.');
                }
                $this->command($hello, [250]);
            }
            if ($s['user'] !== '') {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($s['user']), [334]);
                $this->command(base64_encode($s['pass']), [235]);
            }
            $this->command('MAIL FROM:<' . $s['sender_address'] . '>', [250]);
            foreach ($recipients as $to) {
                $this->command("RCPT TO:<{$to}>", [250, 251]);
            }
            $this->command('DATA', [354]);
            $this->write($this->message($recipients, $subject, $text, $attachment) . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221], false);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private static function header(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    private function message(array $recipients, string $subject, string $text, ?array $attachment): string
    {
        $s = $this->settings;
        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::header($s['sender_name']) . ' <' . $s['sender_address'] . '>',
            'To: ' . implode(', ', array_map(fn ($a) => "<{$a}>", $recipients)),
            'Subject: ' . self::header($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url('http://' . $s['host'], PHP_URL_HOST) ?: 'mealboard') . '>',
            'MIME-Version: 1.0',
        ];
        $body = chunk_split(base64_encode($text), 76, "\r\n");

        if ($attachment === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $headers) . "\r\n\r\n" . $body;
        }

        $boundary = 'mealboard_' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $name = $attachment['filename'];
        $parts = [
            "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $body,
            "--{$boundary}\r\nContent-Type: " . ($attachment['mime'] ?? 'application/octet-stream') . "; name=\"{$name}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                . chunk_split(base64_encode($attachment['content']), 76, "\r\n"),
            "--{$boundary}--\r\n",
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket) || @fwrite($this->socket, $data) === false) {
            throw new RuntimeException('Cannot write to the mail server.');
        }
    }

    private function command(string $line, array $expect, bool $read = true): string
    {
        $this->write($line . "\r\n");
        return $read ? $this->expect($expect) : '';
    }

    private function expect(array $codes): string
    {
        $lines = [];
        while (true) {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                throw new RuntimeException('The mail server closed the connection: ' . trim(implode(' | ', $lines)));
            }
            $lines[] = trim($line);
            $code = (int) substr($line, 0, 3);
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('The mail server answered ' . $code . ': ' . trim(implode(' | ', $lines)));
        }
        return implode(' ', $lines);
    }
}
