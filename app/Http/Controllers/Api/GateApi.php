<?php

namespace App\Http\Controllers\Api;

use App\Services\GateRelay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/gate — the tripod gate's relay for other programs (admins only), the same actions as the old gate server:
 *   { action: 'open' }                     -> { ok, reply } | { ok: false, error }
 *   { action: 'devices' }                  -> { devices, config }
 *   { action: 'test', port, config }       -> sends the message on that port
 *   { action: 'choose', config }           -> saves and returns the config
 * The Serve page of this site does the same work itself, without this address.
 */
class GateApi extends ApiBase
{
    public function handle(Request $request): JsonResponse
    {
        $me = $this->me($request);
        if ((int) $me->Status !== 1 || !$this->isAdmin($me)) {
            return $this->json(['ok' => false, 'error' => 'Only admins can use the gate.'], 403);
        }
        switch ((string) $request->input('action')) {
            case 'open':
                return $this->json(GateRelay::open());
            case 'devices':
                try {
                    return $this->json(['devices' => GateRelay::ports(), 'config' => GateRelay::config()]);
                } catch (\Throwable $e) {
                    return $this->json(['error' => $e->getMessage()], 500);
                }
            case 'test':
                $port = (string) $request->input('port', '');
                if ($port === '') {
                    return $this->json(['ok' => false, 'error' => 'No port given.'], 400);
                }
                return $this->json(GateRelay::pulse($port, GateRelay::config((array) $request->input('config', []))));
            case 'choose':
                return $this->json(GateRelay::save((array) $request->input('config', [])));
            default:
                return $this->json(['ok' => false, 'error' => 'Unknown action.'], 400);
        }
    }
}
