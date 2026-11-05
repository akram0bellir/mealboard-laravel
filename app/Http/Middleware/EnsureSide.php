<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** side:admin = admin, super admin, manager; side:employee = everyone else. Wrong side -> own home page. */
class EnsureSide
{
    public function handle(Request $request, Closure $next, string $side): Response
    {
        $type = $request->user()?->User_Type;
        $isAdminSide = Roles::opensAdminSide($type);

        if (($side === 'admin') !== $isAdminSide) {
            return redirect(Roles::home($type));
        }
        return $next($request);
    }
}
