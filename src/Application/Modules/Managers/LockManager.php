<?php declare(strict_types=1);
namespace Webkernel\Modules\Managers;

use Webkernel\Modules\Exceptions\LockException;
use Illuminate\Support\Facades\File;

final class LockManager
{
  private const string DEFAULT_LOCK_DIR = 'storage/system/locks';
  private const int DEFAULT_TIMEOUT = 300;
  private const int STALE_LOCK_THRESHOLD = 3600;
  private const int POLL_INTERVAL_MICROSECONDS = 100000;

  private string $lockDir;
  private mixed $lockHandle = null;
  private ?string $lockFile = null;
  private int $timeout;

  public function __construct(?string $lockDir = null, int $timeout = self::DEFAULT_TIMEOUT)
  {
    $this->lockDir = $lockDir ?? base_path(self::DEFAULT_LOCK_DIR);
    $this->timeout = $timeout;
  }

  public function acquire(string $operation): void
  {
    File::ensureDirectoryExists($this->lockDir);

    $this->cleanStaleLocks();

    $this->lockFile = $this->lockDir . '/' . md5($operation) . '.lock';
    $this->lockHandle = @fopen($this->lockFile, 'w');

    if (!is_resource($this->lockHandle)) {
      throw new LockException("Cannot create lock file: {$this->lockFile}");
    }

    $startTime = time();
    $acquired = false;

    while (time() - $startTime < $this->timeout) {
      if (@flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
        $acquired = true;
        break;
      }
      usleep(self::POLL_INTERVAL_MICROSECONDS);
    }

    if (!$acquired) {
      @fclose($this->lockHandle);
      $this->lockHandle = null;
      throw new LockException("Cannot acquire lock for '{$operation}' (timeout after {$this->timeout}s)");
    }

    fwrite(
      $this->lockHandle,
      json_encode(
        [
          'operation' => $operation,
          'pid' => getmypid(),
          'timestamp' => time(),
          'hostname' => gethostname(),
        ],
        JSON_THROW_ON_ERROR,
      ),
    );
    fflush($this->lockHandle);
  }

  public function release(): void
  {
    if (is_resource($this->lockHandle)) {
      @flock($this->lockHandle, LOCK_UN);
      @fclose($this->lockHandle);
      $this->lockHandle = null;
    }

    if ($this->lockFile && file_exists($this->lockFile)) {
      @unlink($this->lockFile);
      $this->lockFile = null;
    }
  }

  public function forceRelease(string $operation): void
  {
    $lockFile = $this->lockDir . '/' . md5($operation) . '.lock';

    if (file_exists($lockFile)) {
      @unlink($lockFile);
    }
  }

  public function cleanStaleLocks(): void
  {
    if (!is_dir($this->lockDir)) {
      return;
    }

    $now = time();
    $locks = glob($this->lockDir . '/*.lock');

    if ($locks === false) {
      return;
    }

    foreach ($locks as $lockFile) {
      $age = $now - filemtime($lockFile);

      if ($age > self::STALE_LOCK_THRESHOLD) {
        $content = @file_get_contents($lockFile);

        if ($content !== false) {
          $data = @json_decode($content, true);
          $pid = $data['pid'] ?? null;

          if ($pid && !$this->isProcessRunning((int) $pid)) {
            @unlink($lockFile);
          }
        } else {
          @unlink($lockFile);
        }
      }
    }
  }

  private function isProcessRunning(int $pid): bool
  {
    if (PHP_OS_FAMILY === 'Windows') {
      $output = shell_exec("tasklist /FI \"PID eq {$pid}\" 2>NUL");
      return $output && str_contains($output, (string) $pid);
    }

    return @posix_kill($pid, 0);
  }

  public function __destruct()
  {
    try {
      $this->release();
    } catch (\Throwable) {
    }
  }
}
