<?php

use App\Http\Middleware\AdminPage;
use App\Http\Middleware\EnsureSide;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/');
        $middleware->web(append: [SetLocale::class]);
        // The gate's line ending is "\n" or "\r\n": keep it as sent.
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/gate')]);
        // The control panel checks its own X-CSRF token (same as the PHP version's page script).
        $middleware->validateCsrfTokens(except: ['control']);
        $middleware->alias(['side' => EnsureSide::class, 'admin.page' => AdminPage::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The JSON API answers like the PHP API: {"status": 404, "message": "..."}.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = match (true) {
                $status === 404 => 'Not found.',
                $status === 405 => 'Method not allowed.',
                $status >= 500 => 'Something went wrong on the server.',
                default => $e->getMessage() ?: 'The request was refused.',
            };
            return response()->json(['status' => $status, 'message' => $message], $status);
        });
    })->create();
