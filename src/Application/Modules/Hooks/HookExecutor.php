<?php declare(strict_types=1);

namespace Webkernel\Modules\Hooks;

use Webkernel\Modules\Core\Config;
use Webkernel\Modules\Exceptions\HookException;

final class HookExecutor
{
  private const array FORBIDDEN_FUNCTIONS = [
    'eval',
    'exec',
    'system',
    'passthru',
    'shell_exec',
    'proc_open',
    'popen',
    'pcntl_exec',
    'pcntl_fork',
    'dl',
    'assert',
    'create_function',
  ];

  public function __construct(private int $timeout = Config::HOOK_TIMEOUT) {}

  public function execute(string $hookPath, string $type): void
  {
    if (!file_exists($hookPath)) {
      return;
    }

    $code = file_get_contents($hookPath);
    $this->validateHookCode($code);

    $startTime = time();

    try {
      $this->executeInIsolation($hookPath);

      $duration = time() - $startTime;

      if ($duration > $this->timeout) {
        throw new HookException("Hook execution timeout ({$duration}s > {$this->timeout}s)");
      }
    } catch (\Throwable $e) {
      throw new HookException("Hook execution failed: {$e->getMessage()}", 0, $e);
    }
  }

  private function validateHookCode(string $code): void
  {
    foreach (self::FORBIDDEN_FUNCTIONS as $func) {
      if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $code)) {
        throw new HookException("Hook contains forbidden function: {$func}");
      }
    }

    if (str_contains($code, '`')) {
      throw new HookException('Hook contains forbidden backtick operator');
    }

    if (preg_match('/\$\w+\s*\(/', $code)) {
      throw new HookException('Hook contains potentially dangerous variable function calls');
    }
  }

  private function executeInIsolation(string $hookPath): mixed
  {
    return (fn() => include $hookPath)();
  }
}
