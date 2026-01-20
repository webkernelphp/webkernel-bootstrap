<?php declare(strict_types=1);

namespace Webkernel\Modules\Managers;

use Webkernel\Modules\Core\Config;
use Webkernel\Modules\Exceptions\ModuleException;
use Illuminate\Support\Facades\File;

final class BackupManager
{
  private string $backupPath;

  public function __construct(?string $backupPath = null)
  {
    $this->backupPath = $backupPath ?? base_path(Config::BACKUP_DIR);
  }

  public function createBackup(string $targetDir, string $label): string
  {
    if (!is_dir($targetDir)) {
      throw new ModuleException("Target directory does not exist: {$targetDir}");
    }

    $timestamp = now()->format('Y-m-d_H-i-s');
    $backupDir = "{$this->backupPath}/{$label}_{$timestamp}";

    File::ensureDirectoryExists($this->backupPath);
    File::copyDirectory($targetDir, $backupDir);

    File::put(
      "{$backupDir}/.backup-meta.json",
      json_encode(
        [
          'label' => $label,
          'source' => $targetDir,
          'created_at' => now()->toIso8601String(),
          'size_bytes' => $this->getDirectorySize($backupDir),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
      ),
    );

    return $backupDir;
  }

  public function listBackups(string $label = ''): array
  {
    if (!is_dir($this->backupPath)) {
      return [];
    }

    $pattern = $label ? "{$this->backupPath}/{$label}_*" : "{$this->backupPath}/*";
    $backups = glob($pattern, GLOB_ONLYDIR) ?: [];

    usort($backups, fn($a, $b) => filemtime($b) <=> filemtime($a));

    return $backups;
  }

  public function restoreBackup(string $backupDir, string $targetDir): void
  {
    if (!is_dir($backupDir)) {
      throw new ModuleException("Backup directory does not exist: {$backupDir}");
    }

    if (is_dir($targetDir)) {
      File::deleteDirectory($targetDir);
    }

    File::copyDirectory($backupDir, $targetDir);
  }

  public function cleanOldBackups(string $label = '', int $keepCount = Config::BACKUP_KEEP_COUNT): void
  {
    $backups = $this->listBackups($label);

    if (count($backups) <= $keepCount) {
      return;
    }

    $toRemove = array_slice($backups, $keepCount);

    foreach ($toRemove as $backup) {
      File::deleteDirectory($backup);
    }
  }

  private function getDirectorySize(string $dir): int
  {
    $size = 0;
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $size += $file->getSize();
      }
    }

    return $size;
  }
}
