<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

/**
 * Photos: upload checks, safe paths, serving and thumbnails (same rules as the PHP API's lib/files.php).
 * Files live in uploads/<table>/<row id>/<name>, outside public/.
 */
class Files
{
    public const TABLES = ['user', 'reports'];

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public static function dir(): string
    {
        return rtrim((string) config('mealboard.upload_dir'), '/\\');
    }

    /**
     * Checks and stores one uploaded picture; returns the file name for the `picter` column,
     * or throws a RuntimeException whose message can be shown to the person.
     */
    public static function store(string $table, string $rowId, UploadedFile $file): string
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \RuntimeException('Uploads are not allowed for this table.');
        }
        if (!$file->isValid()) {
            throw new \RuntimeException(t("The picture couldn't be uploaded. Please try again."));
        }
        $max = (int) config('mealboard.max_upload_bytes');
        if ($file->getSize() > $max) {
            throw new \RuntimeException(t('The picture must be under {mb} MB.', ['mb' => (int) round($max / 1048576)]));
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (!isset(self::TYPES[$mime]) || !@getimagesize($file->getRealPath())) {
            throw new \RuntimeException(t('Please choose an image (JPG, PNG or WebP).'));
        }

        $dir = self::dir() . '/' . $table . '/' . $rowId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(t("The picture couldn't be uploaded. Please try again."));
        }

        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $base), '._-') ?: 'photo';
        $name = mb_substr($base, 0, 80) . '_' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . self::TYPES[$mime];

        $file->move($dir, $name);
        return $name;
    }

    /** A path inside uploads/, or null when the request tries anything else. */
    public static function path(string $table, string $rowId, string $name): ?string
    {
        if (!in_array($table, self::TABLES, true) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $rowId)) {
            return null;
        }
        $name = basename($name);
        if ($name === '' || $name === '.' || $name === '..' || !preg_match('/^[A-Za-z0-9._ -]{1,200}$/', $name)) {
            return null;
        }
        $path = self::dir() . '/' . $table . '/' . $rowId . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /** The URL of a stored picture, optionally as a thumbnail ("200x200"). */
    public static function url(string $table, string $rowId, string $name, ?string $thumb = null): string
    {
        $url = '/files/' . rawurlencode($table) . '/' . rawurlencode($rowId) . '/' . rawurlencode($name);
        return $thumb ? $url . '?thumb=' . $thumb : $url;
    }

    /** Resizes next to the original, once; returns the thumbnail path, or null (no GD: the original is sent). */
    public static function thumbnail(string $path, int $maxW, int $maxH): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
            return null;
        }
        $maxW = max(16, min(2000, $maxW));
        $maxH = max(16, min(2000, $maxH));

        $info = @getimagesize($path);
        if (!is_array($info)) {
            return null;
        }
        [$width, $height] = $info;
        $mime = (string) ($info['mime'] ?? '');

        $cache = dirname($path) . sprintf('/.thumb_%dx%d_%s', $maxW, $maxH, basename($path));
        if (is_file($cache) && filemtime($cache) >= filemtime($path)) {
            return $cache;
        }

        $scale = min($maxW / max(1, $width), $maxH / max(1, $height), 1.0);
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            'image/gif' => @imagecreatefromgif($path),
            default => null,
        };
        if (!$source) {
            return null;
        }

        $target = imagecreatetruecolor($newW, $newH);
        if ($mime !== 'image/jpeg') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefilledrectangle($target, 0, 0, $newW, $newH, imagecolorallocatealpha($target, 0, 0, 0, 127));
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($target, $cache, 82),
            'image/png' => imagepng($target, $cache, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($target, $cache, 82) : false,
            'image/gif' => imagegif($target, $cache),
            default => false,
        };
        imagedestroy($target);
        imagedestroy($source);

        return $ok && is_file($cache) ? $cache : null;
    }
}
