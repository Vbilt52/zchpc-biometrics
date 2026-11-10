<?php

// Prefer git-tracked SDK sources in ../hikvision-isapi-master/src/ over vendor/ copy
spl_autoload_register(static function (string $class): void {
    $prefix = 'Shaykhnazar\\HikvisionIsapi\\';
    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
        $file = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'hikvision-isapi-master'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.$relative.'.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
}, true, true);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
