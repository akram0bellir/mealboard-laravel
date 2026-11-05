<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LoginThrottle;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Sign in / first password / sign out (same rules as the PHP API's auth.php and
 * the frontend's login screen).
 */
class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect(Roles::home(Auth::user()->User_Type));
        }
        return view('auth.login', ['mustChange' => session()->has('first_login')]);
    }

    public function login(Request $request): RedirectResponse
    {
        $login = trim((string) $request->input('email'));
        $password = (string) $request->input('password');
        $key = LoginThrottle::key($login, $request->ip());

        if (LoginThrottle::isBlocked($key)) {
            return $this->loginError('Too many failed attempts. Try again in a few minutes.', $login);
        }

        // A username never contains "@", so the two can't be mixed up.
        $user = null;
        if ($login !== '') {
            $column = str_contains($login, '@') ? 'email' : 'username';
            $user = User::whereRaw("LOWER({$column}) = ?", [mb_strtolower($login)])->first();
        }

        // password_verify, not Hash::check: old hashes start with $2a$.
        if ($user === null || $password === '' || !password_verify($password, (string) $user->password)) {
            LoginThrottle::recordFailure($key);
            return $this->loginError('Email, username or password is incorrect.', $login);
        }
        LoginThrottle::clear($key);

        // The door account only works in the desktop door app, never here.
        if (Roles::isDoor($user->User_Type)) {
            return $this->loginError('This account is for the door app only.', $login);
        }
        if (!$user->isActive()) {
            return $this->loginError('This account is deactivated. Please contact the kitchen team.', $login);
        }

        // Signed in with the default password: they choose their own before the app opens.
        if ($password === (string) config('mealboard.default_password')) {
            $request->session()->put('first_login', $user->id);
            return redirect('/');
        }

        return $this->startSession($request, $user);
    }

    public function firstPassword(Request $request): RedirectResponse
    {
        $user = ($id = $request->session()->get('first_login')) ? User::find($id) : null;
        if ($user === null) {
            $request->session()->forget('first_login');
            return $this->loginError('You are signed out.');
        }

        $next = (string) $request->input('password');
        $confirm = (string) $request->input('password_confirmation');
        $error = match (true) {
            mb_strlen($next) < 8 => 'New password must be at least 8 characters.',
            $next === (string) config('mealboard.default_password') => 'Choose a password other than the default one.',
            $next !== $confirm => "The two passwords don't match.",
            default => null,
        };
        if ($error !== null) {
            return back()->with('login_error', $error);
        }

        DB::table('user')->where('id', $user->id)->update([
            'password' => Hash::make($next),
            'tokenKey' => bin2hex(random_bytes(15)),
            'updated' => LoginThrottle::now(),
        ]);
        DB::table('auth_tokens')->where('user_id', $user->id)->delete();
        $request->session()->forget('first_login');

        return $this->startSession($request, $user->fresh());
    }

    public function cancelFirstPassword(Request $request): RedirectResponse
    {
        $request->session()->forget('first_login');
        return redirect('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }

    private function startSession(Request $request, User $user): RedirectResponse
    {
        Auth::login($user);
        $request->session()->regenerate();
        return redirect(Roles::home($user->User_Type));
    }

    private function loginError(string $message, string $login = ''): RedirectResponse
    {
        return redirect('/')->with('login_error', $message)->withInput(['email' => $login]);
    }
}
