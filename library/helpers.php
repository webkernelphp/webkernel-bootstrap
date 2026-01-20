<?php declare(strict_types=1);
use Illuminate\Support\Facades\Process;
use Webkernel\DTOs\CommandResult;

if (!function_exists('system_run')) {
  function system_run(string $command): CommandResult
  {
    $result = Process::run($command);

    return new CommandResult(
      success: $result->successful(),
      output: trim($result->output()),
      error: trim($result->errorOutput()),
    );
  }
}

if (!function_exists('composer_run')) {
  function composer_run(string $script): CommandResult
  {
    return system_run("composer {$script}");
  }
}

if (!function_exists('artisan_run')) {
  function artisan_run(string $command): CommandResult
  {
    return system_run("php artisan {$command}");
  }
}
