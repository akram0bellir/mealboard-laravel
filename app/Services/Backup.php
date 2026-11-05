<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Backup of the database and the photos (port of the PHP API's cron/backup.php).
 *
 * Every run: every table to backups/mealboard-YYYY-MM-DD-HH.sql.gz. Once a day: a zip of the uploads.
 * Keeps 14 days of dumps plus one per week for 3 months, and the last 7 photo zips.
 */
class Backup
{
    public static function dir(): string
    {
        $dir = (string) config('mealboard.backup_dir');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the backup folder: $dir");
        }
        return $dir;
    }

    /** @return array{file: string, size: int, uploads: ?string, warnings: string[]} */
    public static function run(): array
    {
        $dir = self::dir();
        $warnings = [];
        $file = $dir . '/mealboard-' . gmdate('Y-m-d-H') . '.sql.gz';

        try {
            $size = self::dumpDatabase($file);
            @chmod($file, 0600);
            self::log($dir, sprintf('database OK %s (%d bytes)', basename($file), $size));
        } catch (\Throwable $e) {
            self::log($dir, 'database FAILED ' . $e->getMessage());
            self::reportFailure($e->getMessage());
            throw $e;
        }

        $zip = null;
        try {
            if (!class_exists(\ZipArchive::class)) {
                // Never skip the photos silently: say so in the log, on screen and to the super admins.
                $warnings[] = 'The photos were NOT backed up: the PHP "zip" extension is off (turn on extension=zip in php.ini).';
                self::log($dir, 'uploads SKIPPED: the PHP "zip" extension is off (turn on extension=zip in php.ini)');
                self::reportFailure('The photos were not backed up: the PHP "zip" extension is off on the server.');
            } else {
                $zip = self::backupUploads($dir, (string) config('mealboard.upload_dir'));
                if ($zip !== null) {
                    self::log($dir, 'uploads OK ' . basename($zip));
                }
            }
        } catch (\Throwable $e) {
            $warnings[] = 'The photos could not be zipped: ' . $e->getMessage();
            self::log($dir, 'uploads FAILED ' . $e->getMessage());
            self::reportFailure('The photos could not be zipped: ' . $e->getMessage());
        }

        try {
            self::prune($dir);
        } catch (\Throwable $e) {
            self::log($dir, 'prune FAILED ' . $e->getMessage());
        }
        return ['file' => $file, 'size' => (int) @filesize($file), 'uploads' => $zip, 'warnings' => $warnings];
    }

    /** Writes the whole database as SQL into a gzip file. Returns its size. */
    public static function dumpDatabase(string $target): int
    {
        $pdo = DB::connection()->getPdo();
        $handle = gzopen($target, 'wb6');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write ' . $target);
        }
        gzwrite($handle, "-- Mealboard database backup\n-- " . gmdate('c') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(\PDO::FETCH_NUM) as [$table]) {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_NUM);
            $sql = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', (string) ($create[1] ?? ''));
            gzwrite($handle, "DROP TABLE IF EXISTS `$table`;\n$sql;\n\n");

            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC);
            foreach (array_chunk($rows, 100) as $chunk) {
                $columns = array_keys($chunk[0]);
                $values = array_map(fn ($row) => '(' . implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')', $chunk);
                gzwrite($handle, "INSERT INTO `$table` (`" . implode('`, `', $columns) . '`) VALUES ' . implode(",\n", $values) . ";\n");
            }
            if ($rows) {
                gzwrite($handle, "\n");
            }
        }
        gzwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($handle);
        return (int) filesize($target);
    }

    /** Zips the uploads folder, once a day. */
    private static function backupUploads(string $dir, string $uploadDir): ?string
    {
        if (!is_dir($uploadDir)) {
            return null;
        }
        $file = $dir . '/uploads-' . gmdate('Y-m-d') . '.zip';
        if (is_file($file)) {
            return $file;
        }
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create ' . $file);
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploadDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item->isFile() && !str_starts_with($item->getFilename(), '.thumb_')) {
                $zip->addFile($item->getPathname(), substr($item->getPathname(), strlen($uploadDir) + 1));
            }
        }
        $zip->close();
        @chmod($file, 0600);
        return $file;
    }

    /** Keeps 14 days of dumps + one per week for 3 months; the last 7 photo zips. */
    private static function prune(string $dir): void
    {
        $now = time();
        $weeks = [];
        $files = glob($dir . '/mealboard-*.sql.gz') ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach ($files as $file) {
            $age = $now - filemtime($file);
            if ($age <= 14 * 86400) {
                continue;
            }
            if ($age <= 90 * 86400) {
                $week = gmdate('o-W', filemtime($file));
                if (!isset($weeks[$week])) {
                    $weeks[$week] = true;
                    continue;
                }
            }
            @unlink($file);
        }
        $zips = glob($dir . '/uploads-*.zip') ?: [];
        usort($zips, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($zips, 7) as $old) {
            @unlink($old);
        }
    }

    /** The newest backups, for the System page. */
    public static function list(int $limit = 12): array
    {
        $dir = (string) config('mealboard.backup_dir');
        $files = array_merge(glob($dir . '/mealboard-*.sql.gz') ?: [], glob($dir . '/uploads-*.zip') ?: []);
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        return array_map(fn ($f) => ['name' => basename($f), 'size' => (int) filesize($f), 'at' => (int) filemtime($f)], array_slice($files, 0, $limit));
    }

    public static function log(string $dir, string $line): void
    {
        @file_put_contents($dir . '/backup.log', gmdate('c') . ' ' . $line . PHP_EOL, FILE_APPEND);
    }

    /** Last lines of backup.log. */
    public static function logTail(int $lines = 8): array
    {
        $file = (string) config('mealboard.backup_dir') . '/backup.log';
        return is_file($file) ? array_slice(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -$lines) : [];
    }

    /** Tells the super admins when a backup fails. */
    private static function reportFailure(string $message): void
    {
        try {
            if (!Mailer::isOn()) {
                return;
            }
            $emails = DB::table('user')->whereIn('User_Type', ['super_admin', 'super_admain'])->where('Status', 1)->where('email', '<>', '')->pluck('email')->all();
            if ($emails) {
                Mailer::send($emails, 'Mealboard backup failed', "The backup did not complete.\n\n" . $message);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
