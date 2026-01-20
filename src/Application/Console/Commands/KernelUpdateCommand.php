<?php declare(strict_types=1);

namespace Webkernel\Console\Commands;

use Illuminate\Console\Command;
use Webkernel\Modules\Services\ModuleService;
use Webkernel\Modules\Managers\{LockManager, BackupManager, ComposerManager};
use Webkernel\Modules\Core\{WebKernelModuleValidator, ConfigManager};
use Webkernel\Modules\Hooks\HookExecutor;
use Webkernel\Modules\Providers\{GitHubProvider, WebKernelProvider};
use Webkernel\Console\PromptHelper;

/**
 * Update the WebKernel core bootstrap
 *
 * @package Webkernel\Modules\Cli
 */
final class KernelUpdateCommand extends Command
{
  private const string KERNEL_REPO = 'webkernelphp/bootstrap';
  private const string BOOTSTRAP_APP_PATH = 'bootstrap/app.php';
  private const string VERSION_PATTERN = '/public\s+const\s+string\s+VERSION\s*=\s*[\'"]([^\'\"]+)[\'"]/';
  private const int RELEASE_FETCH_TIMEOUT = 30;
  private const int MAX_RELEASES_DISPLAY = 10;
  private const string UPDATE_CANCELLED_MSG = 'Update cancelled';
  private const string UPDATE_SUCCESS_MSG = 'Kernel updated successfully!';
  private const string UPDATE_FAILED_MSG = 'Kernel update failed';
  private const string NO_RELEASES_MSG = 'No kernel releases found';
  private const string NO_VERSION_MSG = 'No version selected';
  private const string VERSION_DETECTION_FAILED_MSG = 'Unable to detect current kernel version';

  /**
   * The name and signature of the console command
   *
   * @var string
   */
  protected $signature = 'webkernel:kernel-update
                            {--with-version= : Specific version to install}
                            {--latest : Install latest version}
                            {--token= : Authentication token}
                            {--no-backup : Skip backup creation}
                            {--no-hooks : Skip hook execution}
                            {--pre-release : Include pre-releases}
                            {--dry-run : Simulate without making changes}
                            {--force : Force update even if same version}';

  /**
   * The console command description
   *
   * @var string
   */
  protected $description = 'Update the WebKernel core bootstrap';

  private ModuleService $service;

  /**
   * Execute the console command
   *
   * @return int Exit code (0 = success, 1 = failure)
   */
  public function handle(): int
  {
    $currentVersion = $this->getCurrentKernelVersion();

    if (!$currentVersion) {
      PromptHelper::error(self::VERSION_DETECTION_FAILED_MSG);
      return 1;
    }

    $this->line("Current kernel version: {$currentVersion}");
    $this->newLine();

    $config = new ConfigManager();
    $this->service = $this->createService();

    $createBackup = !$this->option('no-backup') && PromptHelper::confirm('Create backup before kernel update?', true);

    PromptHelper::warning('This will update the core WebKernel bootstrap');

    if (!PromptHelper::confirm('Continue with kernel update?', false)) {
      PromptHelper::info(self::UPDATE_CANCELLED_MSG);
      return 0;
    }

    $token = $this->option('token') ? (string) $this->option('token') : $config->getToken();
    $provider = new GitHubProvider($token, false);

    $releases = $this->fetchReleases($provider);

    if (!$releases || count($releases) === 0) {
      PromptHelper::error(self::NO_RELEASES_MSG);
      return 1;
    }

    $version = $this->selectVersion($releases, $currentVersion);

    if (!$version) {
      PromptHelper::error(self::NO_VERSION_MSG);
      return 1;
    }

    if ($version === $currentVersion && !$this->option('force')) {
      PromptHelper::info("Already on version {$version}. Use --force to reinstall.");
      return 0;
    }

    if ($version === $currentVersion) {
      PromptHelper::warning("Reinstalling current version {$version}");
    }

    $result = $this->executeUpdate($version, $createBackup);

    return $this->handleUpdateResult($result);
  }

  /**
   * Get current kernel version from bootstrap/app.php
   *
   * @return string|null Current version or null if not found
   */
  private function getCurrentKernelVersion(): ?string
  {
    $bootstrapPath = base_path(self::BOOTSTRAP_APP_PATH);

    if (!file_exists($bootstrapPath)) {
      return null;
    }

    $content = file_get_contents($bootstrapPath);

    if ($content === false) {
      return null;
    }

    if (preg_match(self::VERSION_PATTERN, $content, $matches)) {
      return $matches[1];
    }

    return null;
  }

  /**
   * Fetch releases from GitHub
   *
   * @param GitHubProvider $provider GitHub provider instance
   * @return array<int, array{tag_name: string, name?: string, published_at?: string, prerelease?: bool}>|null
   */
  private function fetchReleases(GitHubProvider $provider): ?array
  {
    $includePreRelease = (bool) $this->option('pre-release');

    $this->newLine();
    $this->line('Fetching kernel releases from ' . self::KERNEL_REPO . '...');

    try {
      $releases = $provider->fetchReleases(self::KERNEL_REPO, $includePreRelease);

      if (!$releases) {
        return null;
      }

      $this->line('Found ' . count($releases) . ' release(s)');
      return $releases;
    } catch (\Exception $e) {
      PromptHelper::error("Failed to fetch releases: {$e->getMessage()}");
      return null;
    }
  }

  /**
   * Execute the kernel update with progress output
   *
   * @param string $version Target version
   * @param bool $createBackup Whether to create backup
   * @return array{success: bool, error?: string, dry_run?: bool, version?: string}
   */
  private function executeUpdate(string $version, bool $createBackup): array
  {
    $this->newLine();
    $this->line("Starting kernel update to {$version}...");

    if ($createBackup) {
      $this->line('  • Creating backup...');
    }

    $this->line('  • Acquiring kernel update lock...');
    $this->line('  • Finding provider for ' . self::KERNEL_REPO . '...');
    $this->line('  • Fetching kernel releases...');
    $this->line("  • Looking for kernel version {$version}...");

    try {
      $result = $this->service->updateKernel($version, $createBackup);

      if ($result['success'] && !isset($result['dry_run'])) {
        $this->line('  • Update completed');
      }

      return $result;
    } catch (\Exception $e) {
      return [
        'success' => false,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Handle update result and display appropriate message
   *
   * @param array{success: bool, error?: string, dry_run?: bool, version?: string} $result
   * @return int Exit code
   */
  private function handleUpdateResult(array $result): int
  {
    if ($result['success']) {
      if (isset($result['dry_run'])) {
        PromptHelper::info('[DRY RUN] Would have updated kernel');
        return 0;
      }

      $this->newLine();
      $this->components->info(self::UPDATE_SUCCESS_MSG);
      $this->line("  Version: {$result['version']}");

      return 0;
    }

    PromptHelper::error($result['error'] ?? self::UPDATE_FAILED_MSG);
    return 1;
  }

  /**
   * Select version from available releases
   *
   * @param array<int, array{tag_name: string, name?: string, published_at?: string, prerelease?: bool}> $releases
   * @param string $currentVersion Current kernel version
   * @return string|null Selected version tag
   */
  private function selectVersion(array $releases, string $currentVersion): ?string
  {
    if ($this->option('with-version')) {
      $requested = (string) $this->option('with-version');

      if (!$this->versionExists($releases, $requested)) {
        PromptHelper::error("Version {$requested} not found in releases");
        return null;
      }

      return $requested;
    }

    if ($this->option('latest')) {
      return $releases[0]['tag_name'];
    }

    $this->newLine();

    /** @var array<string, string> $options */
    $options = [];
    $displayCount = min(count($releases), self::MAX_RELEASES_DISPLAY);

    for ($i = 0; $i < $displayCount; $i++) {
      $release = $releases[$i];
      $isCurrent = $release['tag_name'] === $currentVersion;
      $prerelease = $release['prerelease'] ?? false ? ' [PRE-RELEASE]' : '';
      $current = $isCurrent ? ' [CURRENT]' : '';
      $published = substr($release['published_at'] ?? '', 0, 10);

      $label = sprintf(
        '%s - %s (%s)%s%s',
        $release['tag_name'],
        $release['name'] ?? '',
        $published,
        $prerelease,
        $current,
      );

      $options[$release['tag_name']] = $label;
    }

    return PromptHelper::select('Select kernel version', $options, $releases[0]['tag_name']);
  }

  /**
   * Check if version exists in releases
   *
   * @param array<int, array{tag_name: string}> $releases
   * @param string $version Version to check
   * @return bool True if version exists
   */
  private function versionExists(array $releases, string $version): bool
  {
    foreach ($releases as $release) {
      if ($release['tag_name'] === $version) {
        return true;
      }
    }

    return false;
  }

  /**
   * Create and configure ModuleService instance
   *
   * @return ModuleService
   */
  private function createService(): ModuleService
  {
    $service = new ModuleService(
      new LockManager(),
      new BackupManager(),
      new ComposerManager(),
      new WebKernelModuleValidator(),
      new HookExecutor(),
    );

    $config = new ConfigManager();
    $token = $this->option('token') ? (string) $this->option('token') : $config->getToken();

    $service->addProvider(new GitHubProvider($token, false));
    $service->addProvider(new WebKernelProvider($token));

    if ($this->option('no-hooks')) {
      $service->setExecuteHooks(false);
    }

    if ($this->option('dry-run')) {
      $service->setDryRun(true);
    }

    return $service;
  }
}
