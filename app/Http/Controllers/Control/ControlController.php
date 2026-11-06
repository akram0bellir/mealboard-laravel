<?php

namespace App\Http\Controllers\Control;

use App\Http\Controllers\Controller;
use App\Services\LoginThrottle;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The control panel at /control: for the person who runs the server, not part of the Mealboard app.
 * Same page, look and actions as the PHP version (control/index.php).
 *
 *   GET  /control            the page (sign-in form when signed out)
 *   POST /control?a=<action> JSON actions (session + the panel's own X-CSRF token)
 *
 * Sign in with the fixed login from .env (MEALBOARD_CONTROL_USER / MEALBOARD_CONTROL_PASSWORD, off when empty)
 * or with any active super admin account. Separate from the app's own sign-in.
 */
class ControlController extends Controller
{
    private const IDLE_SECONDS = 2 * 3600;

    private const FIXED_ID = '__fixed__';

    public function show(Request $request): Response
    {
        $dbError = null;
        $user = null;
        try {
            $user = $this->user($request);
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }
        $assets = public_path('control-assets/app.js');

        return response()->view('control', [
            'signedIn' => $user !== null,
            'csrf' => $user !== null ? (string) $request->session()->get('ctl_csrf') : '',
            'name' => $user !== null ? (string) $request->session()->get('ctl_name') : '',
            'version' => (string) @filemtime($assets),
            'dbError' => $dbError,
            'database' => $dbError === null ? DB::connection()->getDatabaseName() : '',
        ])->withHeaders($this->headers());
    }

    public function action(Request $request): JsonResponse
    {
        $action = (string) $request->query('a', '');
        $body = $request->isJson() ? (array) $request->json()->all() : $request->post();

        try {
            if ($action === 'login') {
                return $this->login($request, $body);
            }
            if ($this->user($request) === null) {
                throw new ControlError(401, 'Sign in again.');
            }
            if (!hash_equals((string) $request->session()->get('ctl_csrf', ''), (string) $request->header('X-CSRF', ''))) {
                throw new ControlError(403, 'The page is out of date: reload it.');
            }
            if ($action === 'logout') {
                $this->forget($request);
                return $this->json(['ok' => true]);
            }
        } catch (ControlError $e) {
            return $this->json(['status' => $e->status, 'message' => $e->getMessage()], $e->status);
        }

        $actions = new ControlActions((string) $request->session()->get('ctl_name', ''));
        $logged = !in_array($action, ControlActions::READ_ACTIONS, true);
        try {
            $result = $actions->dispatch($action, $body);
        } catch (ControlError $e) {
            if ($logged && $e->status !== 404) {
                $actions->log($action, false, $e->getMessage());
            }
            return $this->json(['status' => $e->status, 'message' => $e->getMessage()], $e->status);
        } catch (\Throwable $e) {
            report($e);
            if ($logged) {
                $actions->log($action, false, $e->getMessage());
            }
            // Show the real error here: this page exists to find out what is broken.
            return $this->json(['status' => 500, 'message' => class_basename($e) . ': ' . $e->getMessage()], 500);
        }
        if ($logged) {
            $actions->log($action, true, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        }
        return $this->json($result);
    }

    private function login(Request $request, array $body): JsonResponse
    {
        $login = trim((string) ($body['login'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $key = LoginThrottle::key('control|' . $login, $request->ip());
        if (LoginThrottle::isBlocked($key)) {
            throw new ControlError(429, 'Too many failed attempts. Try again in a few minutes.');
        }

        $fixedUser = (string) config('mealboard.control_user');
        $fixedPass = (string) config('mealboard.control_password');
        if ($fixedUser !== '' && $fixedPass !== '' && strcasecmp($login, $fixedUser) === 0 && hash_equals($fixedPass, $password)) {
            LoginThrottle::clear($key);
            return $this->start($request, self::FIXED_ID, $fixedUser);
        }

        $user = $login === '' ? null : DB::table('user')->whereRaw(str_contains($login, '@') ? 'LOWER(email) = ?' : 'LOWER(username) = ?', [mb_strtolower($login)])->first();
        if ($user === null || $password === '' || !password_verify($password, (string) $user->password)) {
            LoginThrottle::recordFailure($key);
            throw new ControlError(400, 'Wrong e-mail/username or password.');
        }
        if (!Roles::isSuper($user->User_Type) || (int) $user->Status !== 1) {
            throw new ControlError(403, 'Only an active super admin can open the control panel.');
        }
        LoginThrottle::clear($key);
        return $this->start($request, (string) $user->id, trim($user->firstname . ' ' . $user->lastname) ?: (string) $user->username);
    }

    private function start(Request $request, string $id, string $name): JsonResponse
    {
        $request->session()->regenerate();
        $request->session()->put([
            'ctl_uid' => $id,
            'ctl_name' => $name,
            'ctl_seen' => time(),
            'ctl_csrf' => bin2hex(random_bytes(24)),
        ]);
        return $this->json(['ok' => true, 'csrf' => $request->session()->get('ctl_csrf'), 'name' => $name]);
    }

    /** The signed-in panel user, re-checked against the database on every request; null when signed out or idle. */
    private function user(Request $request): ?array
    {
        $session = $request->session();
        $id = (string) $session->get('ctl_uid', '');
        if ($id === '') {
            return null;
        }
        if (time() - (int) $session->get('ctl_seen', 0) > self::IDLE_SECONDS) {
            $this->forget($request);
            return null;
        }
        if ($id === self::FIXED_ID) {
            if ((string) config('mealboard.control_user') === '' || (string) config('mealboard.control_password') === '') {
                $this->forget($request);
                return null; // the fixed login was switched off since
            }
            $session->put('ctl_seen', time());
            return ['id' => $id];
        }
        $user = DB::table('user')->where('id', $id)->first();
        if ($user === null || !Roles::isSuper($user->User_Type) || (int) $user->Status !== 1) {
            $this->forget($request);
            return null;
        }
        $session->put('ctl_seen', time());
        return (array) $user;
    }

    /** Signs out of the panel only (the app's own sign-in in the same browser stays). */
    private function forget(Request $request): void
    {
        $request->session()->forget(['ctl_uid', 'ctl_name', 'ctl_seen', 'ctl_csrf']);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, $this->headers(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function headers(): array
    {
        return ['X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'];
    }
}
