<?php
require __DIR__ . '/../vendor/autoload.php';
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Webkernel\CliServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
  ->withRouting(web: __DIR__ . '/../routes/web.php', commands: __DIR__ . '/../routes/console.php', health: '/up')
  ->withMiddleware(function (Middleware $middleware): void {
    //
  })
  ->withExceptions(function (Exceptions $exceptions): void {
    //
  })
  ->withProviders([CliServiceProvider::class])
  ->create();
