<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\TableReader;

$path = $argv[1] ?? 'C:\Users\akram.bellir\Downloads\Classeur1.xlsx';
$baseUrl = rtrim($argv[2] ?? 'http://127.0.0.1:8080', '/');

if (!is_file($path)) {
    fwrite(STDERR, "Spreadsheet not found: {$path}\n");
    exit(1);
}

try {
    $rows = TableReader::rows($path);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if ($rows === []) {
    fwrite(STDERR, "The spreadsheet is empty.\n");
    exit(1);
}

$headerRow = array_key_first($rows);
$column = [];
foreach ($rows[$headerRow] as $i => $heading) {
    $column[mb_strtolower(trim((string) $heading))] = $i;
}
foreach (['user_name', 'pw'] as $required) {
    if (!isset($column[$required])) {
        fwrite(STDERR, "Missing column: {$required}\n");
        exit(1);
    }
}

$request = static function (string $url, array $body = [], ?string $token = null): array {
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: ' . $token;
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($body),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $match)) {
            $status = (int) $match[1];
        }
    }
    $json = is_string($response) ? json_decode($response, true) : null;
    return ['status' => $status, 'json' => is_array($json) ? $json : []];
};

$counts = ['tested' => 0, 'success' => 0, 'failed' => 0, 'invalid_row' => 0, 'http_error' => 0];
$failed = [];

foreach ($rows as $rowNumber => $row) {
    if ($rowNumber === $headerRow) {
        continue;
    }
    $get = fn (string $name) => isset($column[$name]) ? (string) ($row[$column[$name]] ?? '') : '';
    $username = preg_replace('/[\s\x{FFFD}]+/u', '', trim($get('user_name')));
    $password = trim($get('pw'));
    if ($username === '' && $password === '') {
        continue;
    }
    if (!preg_match('/^[\p{L}\p{N}._-]{3,32}$/u', $username) || $password === '') {
        $counts['invalid_row']++;
        continue;
    }

    $counts['tested']++;
    $result = $request($baseUrl . '/api/auth/login', [
        'username' => $username,
        'password' => $password,
    ]);

    if ($result['status'] >= 500 || $result['status'] === 0) {
        $counts['http_error']++;
        $failed[] = $username;
        break;
    }
    if ($result['status'] !== 200) {
        $counts['failed']++;
        $failed[] = $username;
        continue;
    }

    $counts['success']++;
    $token = (string) ($result['json']['token'] ?? '');
    if ($token !== '') {
        $request($baseUrl . '/api/auth/logout', [], $token);
    }
}

echo json_encode([
    'tested' => $counts['tested'],
    'success' => $counts['success'],
    'failed' => $counts['failed'],
    'invalid_row' => $counts['invalid_row'],
    'http_error' => $counts['http_error'],
    'failed_usernames' => $failed,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($counts['failed'] === 0 && $counts['http_error'] === 0 ? 0 : 1);
