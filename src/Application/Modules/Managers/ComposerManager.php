<?php declare(strict_types=1);

namespace Webkernel\Modules\Managers;

use Webkernel\Modules\Exceptions\ComposerException;
use Illuminate\Support\Facades\Process;

/**
 * Note: This manager does NOT modify composer.json
 * It only runs composer dump-autoload when needed
 */
final class ComposerManager
{
  public function dumpAutoload(): void
  {
    $result = Process::run('composer dump-autoload --no-interaction');

    if ($result->failed()) {
      throw new ComposerException("composer dump-autoload failed:\n" . $result->errorOutput());
    }
  }
}
