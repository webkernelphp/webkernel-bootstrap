<?php declare(strict_types=1);

namespace Webkernel\Modules\Services;

use Webkernel\Modules\Core\Contracts\{SourceProvider, ModuleValidator};
use Webkernel\Modules\Core\Config;
use Webkernel\Modules\Managers\{LockManager, BackupManager, ComposerManager};
use Webkernel\Modules\Hooks\HookExecutor;
use Webkernel\Modules\Exceptions\{ModuleException, ValidationException};
use Webkernel\Arcanes\ModuleMetadata;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\OutputInterface;

final class ModuleService
{
  private array $providers = [];
  private bool $executeHooks = true;
  private bool $validateModules = true;
  private bool $dryRun = false;
  private bool $verbose = false;
  private ?OutputInterface $output = null;

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

  public function setVerbose(bool $verbose): void
  {
    $this->verbose = $verbose;
  }

  public function setOutput(?OutputInterface $output): void
  {
    $this->output = $output;
  }

  private function log(string $message): void
  {
    if ($this->verbose && $this->output) {
      $this->output->writeln($message);
    }
  }

  public function installModule(string $identifier, string $version, bool $createBackup): array
  {
    if ($this->dryRun) {
      return ['success' => true, 'dry_run' => true];
    }

    $this->log("  • Acquiring lock for install-{$identifier}...");
    $this->lock->acquire("install-{$identifier}");

    try {
      $this->log("  • Finding provider for {$identifier}...");
      $provider = $this->findProvider($identifier);

      $this->log("  • Fetching releases for {$identifier}...");
      $releases = $provider->fetchReleases($identifier, false);

      if (!$releases || count($releases) === 0) {
        throw new ModuleException('No releases found');
      }

      $this->log("  • Looking for version {$version}...");
      $release = $this->findRelease($releases, $version);

      if (!$release) {
        throw new ModuleException("Version {$version} not found");
      }

      $tempDir = base_path(Config::MODULE_DIR . '/.installing-' . uniqid());
      $backupDir = null;

      try {
        $this->log("  • Downloading release to {$tempDir}...");
        $provider->downloadRelease($release, $tempDir);

        if ($this->executeHooks) {
          $hookFile = "{$tempDir}/webkernel-install.php";
          if (file_exists($hookFile)) {
            $this->log('  • Executing install hook...');
            $this->hookExecutor->execute($hookFile, 'install');
          }
        }

        if ($this->validateModules) {
          $this->log('  • Validating module...');
          $validationResult = $this->validator->validate($tempDir);
          if (!$validationResult->isValid) {
            throw new ValidationException("Module validation failed:\n" . implode("\n", $validationResult->errors));
          }
        }

        $this->log('  • Extracting module metadata...');
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
          $this->log("  • Backup created at: {$backupDir}");
        }

        if (is_dir($targetDir)) {
          $this->log('  • Removing existing module directory...');
          File::deleteDirectory($targetDir);
        }

        $this->log("  • Installing module to {$targetDir}...");
        File::ensureDirectoryExists(dirname($targetDir));
        File::move($tempDir, $targetDir);

        $this->log('  • Dumping composer autoload...');
        $this->composer->dumpAutoload();

        if ($createBackup) {
          $this->log('  • Cleaning old backups...');
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
          $this->log('  • Cleaning up temporary directory...');
          File::deleteDirectory($tempDir);
        }

        if ($backupDir && is_dir($backupDir)) {
          $this->log('  • Restoring from backup...');
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

    $this->log('  • Acquiring kernel update lock...');
    $this->lock->acquire('update-kernel');

    try {
      $this->log('  • Finding provider for webkernelphp/bootstrap...');
      $provider = $this->findProvider('webkernelphp/bootstrap');

      $this->log('  • Fetching kernel releases...');
      $releases = $provider->fetchReleases('webkernelphp/bootstrap', false);

      if (!$releases || count($releases) === 0) {
        throw new ModuleException('No kernel releases found');
      }

      $this->log("  • Looking for kernel version {$version}...");
      $release = $this->findRelease($releases, $version);

      if (!$release) {
        throw new ModuleException("Kernel version {$version} not found");
      }

      $baseDir = base_path(Config::BOOTSTRAP_DIR);
      $tempDir = $baseDir . '.updating';
      $backupDir = sys_get_temp_dir() . '/wk-backup-' . uniqid();
      $preserved = ['cache', 'var-elements'];

      if ($createBackup && is_dir($baseDir)) {
        $backupPath = $this->backup->createBackup($baseDir, 'kernel');
        $this->log("  • Backup created at: {$backupPath}");
      }

      try {
        $this->log('  • Preserving directories: ' . implode(', ', $preserved));
        $this->backupDirs($baseDir, $preserved, $backupDir);

        $this->log("  • Downloading kernel to {$tempDir}...");
        $provider->downloadRelease($release, $tempDir);

        $this->log('  • Restoring preserved directories...');
        $this->restoreDirs($backupDir, $tempDir, $preserved);

        if ($this->executeHooks) {
          $hookFile = "{$tempDir}/webkernel-update.php";
          if (file_exists($hookFile)) {
            $this->log('  • Executing update hook...');
            $this->hookExecutor->execute($hookFile, 'update');
          }
        }

        if (is_dir($baseDir)) {
          $oldDir = $baseDir . '.old';
          if (is_dir($oldDir)) {
            $this->log('  • Removing old kernel directory...');
            File::deleteDirectory($oldDir);
          }

          $this->log('  • Moving current kernel to .old...');
          File::move($baseDir, $oldDir);

          $this->log('  • Installing new kernel...');
          File::move($tempDir, $baseDir);

          $this->log('  • Cleaning up old kernel...');
          File::deleteDirectory($oldDir);
        } else {
          $this->log('  • Installing new kernel...');
          File::move($tempDir, $baseDir);
        }

        $this->log('  • Cleaning up temporary backup...');
        File::deleteDirectory($backupDir);

        if ($createBackup) {
          $this->log('  • Cleaning old kernel backups...');
          $this->backup->cleanOldBackups('kernel');
        }

        return [
          'success' => true,
          'version' => $release['tag_name'],
        ];
      } catch (\Exception $e) {
        if (isset($tempDir) && is_dir($tempDir)) {
          $this->log('  • Cleaning up temporary directory...');
          File::deleteDirectory($tempDir);
        }

        if (isset($backupDir) && is_dir($backupDir)) {
          $this->log('  • Cleaning up backup directory...');
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
