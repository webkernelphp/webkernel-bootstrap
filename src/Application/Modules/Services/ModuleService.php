<?php declare(strict_types=1);

namespace Webkernel\Modules\Services;

use Webkernel\Modules\Core\Contracts\{SourceProvider, ModuleValidator};
use Webkernel\Modules\Core\Config;
use Webkernel\Modules\Managers\{LockManager, BackupManager, ComposerManager};
use Webkernel\Modules\Hooks\HookExecutor;
use Webkernel\Modules\Exceptions\{ModuleException, ValidationException};
use Webkernel\Arcanes\ModuleMetadata;
use Illuminate\Support\Facades\File;

final class ModuleService
{
  private array $providers = [];
  private bool $executeHooks = true;
  private bool $validateModules = true;
  private bool $dryRun = false;

  public function __construct(
    private LockManager $lock,
    private BackupManager $backup,
    private ComposerManager $composer,
    private ModuleValidator $validator,
    private HookExecutor $hookExecutor,
  ) {}

  public function addProvider(SourceProvider $provider): void
  {
    $this->providers[] = $provider;
  }

  public function setExecuteHooks(bool $execute): void
  {
    $this->executeHooks = $execute;
  }

  public function setValidateModules(bool $validate): void
  {
    $this->validateModules = $validate;
  }

  public function setDryRun(bool $dryRun): void
  {
    $this->dryRun = $dryRun;
  }

  public function installModule(string $identifier, string $version, bool $createBackup): array
  {
    if ($this->dryRun) {
      return ['success' => true, 'dry_run' => true];
    }

    $this->lock->acquire("install-{$identifier}");

    try {
      $provider = $this->findProvider($identifier);
      $releases = $provider->fetchReleases($identifier, false);

      if (!$releases || count($releases) === 0) {
        throw new ModuleException('No releases found');
      }

      $release = $this->findRelease($releases, $version);

      if (!$release) {
        throw new ModuleException("Version {$version} not found");
      }

      $tempDir = base_path(Config::MODULE_DIR . '/.installing-' . uniqid());
      $backupDir = null;

      try {
        $provider->downloadRelease($release, $tempDir);

        if ($this->executeHooks) {
          $hookFile = "{$tempDir}/webkernel-install.php";
          if (file_exists($hookFile)) {
            $this->hookExecutor->execute($hookFile, 'install');
          }
        }

        if ($this->validateModules) {
          $validationResult = $this->validator->validate($tempDir);

          if (!$validationResult->isValid) {
            throw new ValidationException("Module validation failed:\n" . implode("\n", $validationResult->errors));
          }
        }

        $metadata = $this->extractModuleMetadata($tempDir);

        if ($metadata === null) {
          throw new ModuleException('Cannot extract module metadata - no valid module file found');
        }

        if ($metadata->installPath === '') {
          throw new ModuleException('Module does not declare installPath() in configureModule()');
        }

        if ($metadata->namespace === '') {
          throw new ModuleException('Module does not declare namespace in installPath()');
        }

        $targetDir = base_path($metadata->installPath);

        if ($createBackup && is_dir($targetDir)) {
          $backupDir = $this->backup->createBackup($targetDir, basename($metadata->installPath));
        }

        if (is_dir($targetDir)) {
          File::deleteDirectory($targetDir);
        }

        File::ensureDirectoryExists(dirname($targetDir));
        File::move($tempDir, $targetDir);
        $this->composer->dumpAutoload();

        if ($createBackup) {
          $this->backup->cleanOldBackups(basename($metadata->installPath));
        }

        return [
          'success' => true,
          'path' => $targetDir,
          'version' => $release['tag_name'],
          'namespace' => $metadata->namespace,
          'install_path' => $metadata->installPath,
        ];
      } catch (\Exception $e) {
        if (isset($tempDir) && is_dir($tempDir)) {
          File::deleteDirectory($tempDir);
        }

        if ($backupDir && is_dir($backupDir)) {
          $this->backup->restoreBackup($backupDir, $targetDir);
        }

        throw $e;
      }
    } catch (\Exception $e) {
      return [
        'success' => false,
        'error' => $e->getMessage(),
      ];
    } finally {
      $this->lock->release();
    }
  }

  public function updateKernel(string $version, bool $createBackup): array
  {
    if ($this->dryRun) {
      return ['success' => true, 'dry_run' => true];
    }

    $this->lock->acquire('update-kernel');

    try {
      $provider = $this->findProvider('webkernelphp/bootstrap');
      $releases = $provider->fetchReleases('webkernelphp/bootstrap', false);

      if (!$releases || count($releases) === 0) {
        throw new ModuleException('No kernel releases found');
      }

      $release = $this->findRelease($releases, $version);

      if (!$release) {
        throw new ModuleException("Kernel version {$version} not found");
      }

      $baseDir = base_path(Config::BOOTSTRAP_DIR);
      $tempDir = $baseDir . '.updating';
      $backupDir = sys_get_temp_dir() . '/wk-backup-' . uniqid();
      $preserved = ['cache', 'var-elements'];

      if ($createBackup && is_dir($baseDir)) {
        $this->backup->createBackup($baseDir, 'kernel');
      }

      try {
        $this->backupDirs($baseDir, $preserved, $backupDir);
        $provider->downloadRelease($release, $tempDir);
        $this->restoreDirs($backupDir, $tempDir, $preserved);

        if ($this->executeHooks) {
          $hookFile = "{$tempDir}/webkernel-update.php";
          if (file_exists($hookFile)) {
            $this->hookExecutor->execute($hookFile, 'update');
          }
        }

        if (is_dir($baseDir)) {
          $oldDir = $baseDir . '.old';
          if (is_dir($oldDir)) {
            File::deleteDirectory($oldDir);
          }
          File::move($baseDir, $oldDir);
          File::move($tempDir, $baseDir);
          File::deleteDirectory($oldDir);
        } else {
          File::move($tempDir, $baseDir);
        }

        File::deleteDirectory($backupDir);

        if ($createBackup) {
          $this->backup->cleanOldBackups('kernel');
        }

        return [
          'success' => true,
          'version' => $release['tag_name'],
        ];
      } catch (\Exception $e) {
        if (isset($tempDir) && is_dir($tempDir)) {
          File::deleteDirectory($tempDir);
        }
        if (isset($backupDir) && is_dir($backupDir)) {
          File::deleteDirectory($backupDir);
        }
        throw $e;
      }
    } catch (\Exception $e) {
      return [
        'success' => false,
        'error' => $e->getMessage(),
      ];
    } finally {
      $this->lock->release();
    }
  }

  public function listInstalledModules(): array
  {
    $modules = [];
    $moduleDir = base_path(Config::MODULE_DIR);

    if (!is_dir($moduleDir)) {
      return $modules;
    }

    $vendors = File::directories($moduleDir);

    foreach ($vendors as $vendorPath) {
      $vendor = basename($vendorPath);
      $modulePaths = File::directories($vendorPath);

      foreach ($modulePaths as $modulePath) {
        $module = basename($modulePath);
        $metadata = $this->extractModuleMetadata($modulePath);

        $modules[] = [
          'vendor' => $vendor,
          'module' => $module,
          'path' => $modulePath,
          'version' => $metadata?->version ?? 'unknown',
          'name' => $metadata?->name ?? $module,
          'namespace' => $metadata?->namespace ?? '',
        ];
      }
    }

    return $modules;
  }

  private function findProvider(string $identifier): SourceProvider
  {
    foreach ($this->providers as $provider) {
      if ($provider->supports($identifier)) {
        return $provider;
      }
    }

    throw new ModuleException('No provider found for this source');
  }

  private function findRelease(array $releases, string $version): ?array
  {
    foreach ($releases as $release) {
      if ($release['tag_name'] === $version) {
        return $release;
      }
    }

    return null;
  }

  private function extractModuleMetadata(string $modulePath): ?ModuleMetadata
  {
    $files = File::glob($modulePath . '/*Module.php');

    foreach ($files as $file) {
      if (is_file($file)) {
        return ModuleMetadata::fromModuleFile($file);
      }
    }

    return null;
  }

  private function backupDirs(string $base, array $dirs, string $backup): void
  {
    File::ensureDirectoryExists($backup);

    foreach ($dirs as $dir) {
      $src = "{$base}/{$dir}";
      if (is_dir($src)) {
        File::copyDirectory($src, "{$backup}/{$dir}");
      }
    }
  }

  private function restoreDirs(string $backup, string $base, array $dirs): void
  {
    foreach ($dirs as $dir) {
      $src = "{$backup}/{$dir}";
      if (is_dir($src)) {
        $dest = "{$base}/{$dir}";
        if (is_dir($dest)) {
          File::deleteDirectory($dest);
        }
        File::copyDirectory($src, $dest);
      }
    }
  }
}
