<?php

namespace App\Http\Controllers;

use App\Services\Files;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** GET /files/{table}/{id}/{name}[?thumb=200x200] — a stored photo (public, like the PHP API). */
class FileController extends Controller
{
    public function show(Request $request, string $table, string $id, string $name): BinaryFileResponse
    {
        $path = Files::path($table, $id, $name);
        abort_if($path === null, 404);

        $send = $path;
        if (preg_match('/^(\d{1,4})x(\d{1,4})$/', (string) $request->query('thumb'), $m)) {
            $send = Files::thumbnail($path, (int) $m[1], (int) $m[2]) ?? $path;
        }

        return response()->file($send, [
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
