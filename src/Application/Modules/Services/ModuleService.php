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
  private const string BOOTSTRAP_REPO = 'webkernelphp/bootstrap';
  private const string TEMP_SUFFIX_INSTALLING = '.installing-';
  private const string TEMP_SUFFIX_UPDATING = '.updating';
  private const string TEMP_SUFFIX_OLD = '.old';
  private const string TEMP_PREFIX_BACKUP = 'wk-backup-';
  private const string KERNEL_BACKUP_NAME = 'kernel';
  private const array PRESERVED_DIRS = ['var-elements'];
  private const array EXCLUDED_BACKUP_PATTERNS = ['*/backups/*', '*/locks/*', '*.lock'];
  private const string HOOK_INSTALL_FILE = 'webkernel-install.php';
  private const string HOOK_UPDATE_FILE = 'webkernel-update.php';
  private const string MODULE_FILE_PATTERN = '/*Module.php';

  private const string LOG_ACQUIRE_LOCK = '  • Acquiring lock for %s...';
  private const string LOG_FIND_PROVIDER = '  • Finding provider for %s...';
  private const string LOG_FETCH_RELEASES = '  • Fetching releases for %s...';
  private const string LOG_LOOK_VERSION = '  • Looking for version %s...';
  private const string LOG_DOWNLOAD = '  • Downloading release to %s...';
  private const string LOG_EXEC_HOOK = '  • Executing %s hook...';
  private const string LOG_VALIDATE = '  • Validating module...';
  private const string LOG_EXTRACT_META = '  • Extracting module metadata...';
  private const string LOG_BACKUP_CREATED = '  • Backup created at: %s';
  private const string LOG_REMOVE_DIR = '  • Removing existing %s directory...';
  private const string LOG_INSTALL_TO = '  • Installing %s to %s...';
  private const string LOG_DUMP_AUTOLOAD = '  • Dumping composer autoload...';
  private const string LOG_CLEAN_BACKUPS = '  • Cleaning old backups...';
  private const string LOG_CLEANUP_TEMP = '  • Cleaning up temporary directory...';
  private const string LOG_RESTORE_BACKUP = '  • Restoring from backup...';
  private const string LOG_PRESERVE_DIRS = '  • Preserving directories: %s';
  private const string LOG_RESTORE_PRESERVED = '  • Restoring preserved directories...';
  private const string LOG_MOVE_TO_OLD = '  • Moving current kernel to .old...';
  private const string LOG_CLEANUP_OLD = '  • Cleaning up old kernel...';

  private const string ERR_NO_RELEASES = 'No releases found';
  private const string ERR_VERSION_NOT_FOUND = 'Version %s not found';
  private const string ERR_NO_PROVIDER = 'No provider found for this source';
  private const string ERR_NO_METADATA = 'Cannot extract module metadata - no valid module file found';
  private const string ERR_NO_INSTALL_PATH = 'Module does not declare installPath() in configureModule()';
  private const string ERR_NO_NAMESPACE = 'Module does not declare namespace in installPath()';
  private const string ERR_VALIDATION_FAILED = 'Module validation failed:\n%s';
  private const string ERR_NO_KERNEL_RELEASES = 'No kernel releases found';
  private const string ERR_KERNEL_VERSION_NOT_FOUND = 'Kernel version %s not found';

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

  private function log(string $message, mixed ...$args): void
  {
    if ($this->verbose && $this->output) {
      $formatted = count($args) > 0 ? sprintf($message, ...$args) : $message;
      $this->output->writeln($formatted);
    }
  }

  public function installModule(string $identifier, string $version, bool $createBackup): array
  {
    if ($this->dryRun) {
      return ['success' => true, 'dry_run' => true];
    }

    $this->log(self::LOG_ACQUIRE_LOCK, "install-{$identifier}");
    $this->lock->acquire("install-{$identifier}");

    try {
      $this->log(self::LOG_FIND_PROVIDER, $identifier);
      $provider = $this->findProvider($identifier);

      $this->log(self::LOG_FETCH_RELEASES, $identifier);
      $releases = $provider->fetchReleases($identifier, false);

      if (!$releases || count($releases) === 0) {
        throw new ModuleException(self::ERR_NO_RELEASES);
      }

      $this->log(self::LOG_LOOK_VERSION, $version);
      $release = $this->findRelease($releases, $version);

      if (!$release) {
        throw new ModuleException(sprintf(self::ERR_VERSION_NOT_FOUND, $version));
      }

      $tempDir = base_path(Config::MODULE_DIR . '/' . self::TEMP_SUFFIX_INSTALLING . uniqid());
      $backupDir = null;

      try {
        $this->log(self::LOG_DOWNLOAD, $tempDir);
        $provider->downloadRelease($release, $tempDir);

        if ($this->executeHooks) {
          $hookFile = "{$tempDir}/" . self::HOOK_INSTALL_FILE;
          if (file_exists($hookFile)) {
            $this->log(self::LOG_EXEC_HOOK, 'install');
            $this->hookExecutor->execute($hookFile, 'install');
          }
        }

        if ($this->validateModules) {
          $this->log(self::LOG_VALIDATE);
          $validationResult = $this->validator->validate($tempDir);
          if (!$validationResult->isValid) {
            throw new ValidationException(
              sprintf(self::ERR_VALIDATION_FAILED, implode("\n", $validationResult->errors)),
            );
          }
        }

        $this->log(self::LOG_EXTRACT_META);
        $metadata = $this->extractModuleMetadata($tempDir);

        if ($metadata === null) {
          throw new ModuleException(self::ERR_NO_METADATA);
        }

        if ($metadata->installPath === '') {
          throw new ModuleException(self::ERR_NO_INSTALL_PATH);
        }

        if ($metadata->namespace === '') {
          throw new ModuleException(self::ERR_NO_NAMESPACE);
        }

        $targetDir = base_path($metadata->installPath);

        if ($createBackup && is_dir($targetDir)) {
          $backupDir = $this->backup->createBackup($targetDir, basename($metadata->installPath));
          $this->log(self::LOG_BACKUP_CREATED, $backupDir);
        }

        if (is_dir($targetDir)) {
          $this->log(self::LOG_REMOVE_DIR, 'module');
          File::deleteDirectory($targetDir);
        }

        $this->log(self::LOG_INSTALL_TO, 'module', $targetDir);
        File::ensureDirectoryExists(dirname($targetDir));
        File::move($tempDir, $targetDir);

        $this->log(self::LOG_DUMP_AUTOLOAD);
        $this->composer->dumpAutoload();

        if ($createBackup) {
          $this->log(self::LOG_CLEAN_BACKUPS);
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
          $this->log(self::LOG_CLEANUP_TEMP);
          File::deleteDirectory($tempDir);
        }

        if ($backupDir && is_dir($backupDir)) {
          $this->log(self::LOG_RESTORE_BACKUP);
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

    $this->log(self::LOG_ACQUIRE_LOCK, 'update-kernel');
    $this->lock->acquire('update-kernel');

    try {
      $baseDir = base_path(Config::BOOTSTRAP_DIR);

      $this->cleanRecursiveBackups($baseDir);

      $this->log(self::LOG_FIND_PROVIDER, self::BOOTSTRAP_REPO);
      $provider = $this->findProvider(self::BOOTSTRAP_REPO);

      $this->log(self::LOG_FETCH_RELEASES, 'kernel');
      $releases = $provider->fetchReleases(self::BOOTSTRAP_REPO, false);

      if (!$releases || count($releases) === 0) {
        throw new ModuleException(self::ERR_NO_KERNEL_RELEASES);
      }

      $this->log(self::LOG_LOOK_VERSION, $version);
      $release = $this->findRelease($releases, $version);

      if (!$release) {
        throw new ModuleException(sprintf(self::ERR_KERNEL_VERSION_NOT_FOUND, $version));
      }

      $baseDir = base_path(Config::BOOTSTRAP_DIR);
      $tempDir = $baseDir . self::TEMP_SUFFIX_UPDATING;
      $backupDir = sys_get_temp_dir() . '/' . self::TEMP_PREFIX_BACKUP . uniqid();

      if ($createBackup && is_dir($baseDir)) {
        $backupPath = $this->backup->createBackup($baseDir, self::KERNEL_BACKUP_NAME);
        $this->log(self::LOG_BACKUP_CREATED, $backupPath);
      }

      try {
        $this->log(self::LOG_PRESERVE_DIRS, implode(', ', self::PRESERVED_DIRS));
        $this->backupDirs($baseDir, self::PRESERVED_DIRS, $backupDir);

        $this->log(self::LOG_DOWNLOAD, $tempDir);
        $provider->downloadRelease($release, $tempDir);

        $this->log(self::LOG_RESTORE_PRESERVED);
        $this->restoreDirs($backupDir, $tempDir, self::PRESERVED_DIRS);

        if ($this->executeHooks) {
          $hookFile = "{$tempDir}/" . self::HOOK_UPDATE_FILE;
          if (file_exists($hookFile)) {
            $this->log(self::LOG_EXEC_HOOK, 'update');
            $this->hookExecutor->execute($hookFile, 'update');
          }
        }

        if (is_dir($baseDir)) {
          $oldDir = $baseDir . self::TEMP_SUFFIX_OLD;

          if (is_dir($oldDir)) {
            $this->log(self::LOG_REMOVE_DIR, 'old kernel');
            File::deleteDirectory($oldDir);
          }

          $this->log(self::LOG_MOVE_TO_OLD);
          File::move($baseDir, $oldDir);

          $this->log(self::LOG_INSTALL_TO, 'kernel', $baseDir);
          File::move($tempDir, $baseDir);

          $this->log(self::LOG_CLEANUP_OLD);
          File::deleteDirectory($oldDir);
        } else {
          $this->log(self::LOG_INSTALL_TO, 'kernel', $baseDir);
          File::move($tempDir, $baseDir);
        }

        $this->log(self::LOG_CLEANUP_TEMP);
        File::deleteDirectory($backupDir);

        if ($createBackup) {
          $this->log(self::LOG_CLEAN_BACKUPS);
          $this->backup->cleanOldBackups(self::KERNEL_BACKUP_NAME);
        }

        return [
          'success' => true,
          'version' => $release['tag_name'],
        ];
      } catch (\Exception $e) {
        if (isset($tempDir) && is_dir($tempDir)) {
          $this->log(self::LOG_CLEANUP_TEMP);
          File::deleteDirectory($tempDir);
        }

        if (isset($backupDir) && is_dir($backupDir)) {
          $this->log(self::LOG_CLEANUP_TEMP);
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

    throw new ModuleException(self::ERR_NO_PROVIDER);
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
    $files = File::glob($modulePath . self::MODULE_FILE_PATTERN);

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

  private function cleanRecursiveBackups(string $baseDir): void
  {
    $cacheDir = "{$baseDir}/cache";

    if (!is_dir($cacheDir)) {
      return;
    }

    $backupPatterns = ["{$cacheDir}/webkernel/backups", "{$cacheDir}/**/backups"];

    foreach ($backupPatterns as $pattern) {
      $matches = glob($pattern, GLOB_ONLYDIR);
      if ($matches === false) {
        continue;
      }

      foreach ($matches as $backupDir) {
        if (is_dir($backupDir) && str_contains($backupDir, '/backups')) {
          $this->log('  • Cleaning recursive backup: %s', basename($backupDir));
          File::deleteDirectory($backupDir);
        }
      }
    }

    $lockDirs = glob("{$cacheDir}/**/.locks", GLOB_ONLYDIR);
    if ($lockDirs !== false) {
      foreach ($lockDirs as $lockDir) {
        if (is_dir($lockDir)) {
          $this->log('  • Cleaning old locks: %s', $lockDir);
          File::deleteDirectory($lockDir);
        }
      }
    }
  }
}
