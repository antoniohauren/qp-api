<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->report(function (RequestException $exception): void {
            Log::warning('Travel provider request failed.', [
                'exception' => $exception::class,
                'status' => $exception->response->status(),
                'route' => request()->route()?->getName(),
            ]);
        })->stop();

        $exceptions->report(function (ConnectionException $exception): void {
            Log::warning('Travel provider connection failed.', [
                'exception' => $exception::class,
                'route' => request()->route()?->getName(),
            ]);
        })->stop();

        $exceptions->render(function (RequestException $exception): JsonResponse {
            return response()->json(
                ['errors' => ['The travel provider is currently unavailable.']],
                502,
            );
        });

        $exceptions->render(function (ConnectionException $exception): JsonResponse {
            return response()->json(['errors' => ['The travel provider is currently unavailable.']], 502);
        });
    })->create();
