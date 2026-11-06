<?php

use App\Http\Middleware\JwtAuth;
use App\Http\Middleware\OptionalJwt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['jwt' => JwtAuth::class]);
        $middleware->appendToGroup('api', OptionalJwt::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json($e->errors(), 400);
            }
        });
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['detail' => $e->getMessage() ?: 'Request failed.'], $e->getStatusCode());
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
