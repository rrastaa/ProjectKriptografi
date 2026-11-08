<?php

use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use App\Http\Kernel as AppHttpKernel;

return (function () {
    $app = Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__ . '/../routes/web.php',
            api: __DIR__ . '/../routes/api.php',
            commands: __DIR__ . '/../routes/console.php',
            health: '/up',
        )
        ->withMiddleware(function (Middleware $middleware) {
            $middleware->alias([
                'caselock.auth' => ApiTokenAuth::class,
                'caselock.role' => RequireRole::class,
            ]);
            $middleware->api(append: [SecurityHeaders::class]);
        })
        ->withExceptions(function (Exceptions $exceptions) {
            // optional custom exception handling
        })
        ->create();

    // **Kunci ini memastikan Laravel memakai kernel custom Anda**
    $app->singleton(
        HttpKernelContract::class,
        AppHttpKernel::class
    );

    return $app;
})();