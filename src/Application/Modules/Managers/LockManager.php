<?php declare(strict_types=1);

namespace Webkernel\Modules\Managers;

use Webkernel\Modules\Core\Config;
use Webkernel\Modules\Exceptions\LockException;
use Illuminate\Support\Facades\File;

final class LockManager
{
  private string $lockDir;
  private mixed $lockHandle = null;
  private ?string $lockFile = null;
  private int $timeout;

  public function __construct(?string $lockDir = null, int $timeout = Config::LOCK_TIMEOUT)
  {
    $this->lockDir = $lockDir ?? base_path(Config::LOCK_DIR);
    $this->timeout = $timeout;
  }

  public function acquire(string $operation): void
  {
    File::ensureDirectoryExists($this->lockDir);

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
      usleep(100000);
    }

    if (!$acquired) {
      @fclose($this->lockHandle);
      $this->lockHandle = null;
      throw new LockException("Cannot acquire lock for '{$operation}' (timeout after {$this->timeout}s)");
    }

    fwrite(
      $this->lockHandle,
      json_encode([
        'operation' => $operation,
        'pid' => getmypid(),
        'timestamp' => time(),
      ]),
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

  public function __destruct()
  {
    try {
      $this->release();
    } catch (\Throwable) {
    }
  }
}
