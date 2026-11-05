<?php

namespace App\Http\Controllers\Api;

use App\Services\Cron;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET|POST /api/cron-run?key=SECRET — for cron-job.org or the host's cron. A failed step answers 500, so the run shows as failed there. */
class CronApi extends ApiBase
{
    public function run(Request $request): JsonResponse
    {
        $given = (string) ($request->query('key') ?? $request->header('X-Cron-Key', ''));
        $expected = (string) config('mealboard.cron_key');
        if ($expected === '' || !hash_equals($expected, $given)) {
            $this->fail(403, 'Forbidden.');
        }
        $result = Cron::run('web');
        return $this->json($result, isset($result['errors']) ? 500 : 200);
    }
}
