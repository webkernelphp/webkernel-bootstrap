<?php declare(strict_types=1);
namespace Webkernel\Console\Commands;

use Illuminate\Console\Command;
use Webkernel\Modules\Services\ModuleService;
use Webkernel\Modules\Managers\{LockManager, BackupManager, ComposerManager};
use Webkernel\Modules\Core\{WebKernelModuleValidator, ConfigManager};
use Webkernel\Modules\Hooks\HookExecutor;
use Webkernel\Modules\Providers\{GitHubProvider, WebKernelProvider};
use Webkernel\Console\PromptHelper;

final class KernelUpdateCommand extends Command
{
  private const string KERNEL_REPO = 'webkernelphp/bootstrap';
  private const string BOOTSTRAP_APP_PATH = 'bootstrap/app.php';
  private const string VERSION_PATTERN = '/public\s+const\s+string\s+VERSION\s*=\s*[\'"]([^\'\"]+)[\'"]/';
  private const int MAX_RELEASES_DISPLAY = 10;
  private const string UPDATE_CANCELLED_MSG = 'Update cancelled';
  private const string UPDATE_SUCCESS_MSG = 'Kernel updated successfully!';
  private const string UPDATE_FAILED_MSG = 'Kernel update failed';
  private const string NO_RELEASES_MSG = 'No kernel releases found';
  private const string NO_VERSION_MSG = 'No version selected';
  private const string VERSION_DETECTION_FAILED_MSG = 'Unable to detect current kernel version';
  private const string ALREADY_ON_VERSION_MSG = 'Already on version %s. Use --force to reinstall.';
  private const string REINSTALLING_VERSION_MSG = 'Reinstalling current version %s';
  private const string FETCHING_RELEASES_MSG = 'Fetching kernel releases from %s...';
  private const string FOUND_RELEASES_MSG = 'Found %d release(s)';
  private const string VERSION_NOT_FOUND_MSG = 'Version %s not found in releases';
  private const string FETCH_FAILED_MSG = 'Failed to fetch releases: %s';
  private const string CURRENT_VERSION_MSG = 'Current kernel version: %s';

  protected $signature = 'webkernel:kernel-update
                            {--with-version= : Specific version to install}
                            {--latest : Install latest version}
                            {--token= : Authentication token}
                            {--no-backup : Skip backup creation}
                            {--no-hooks : Skip hook execution}
                            {--pre-release : Include pre-releases}
                            {--dry-run : Simulate without making changes}
                            {--force : Force update even if same version}
                            {--debug : Enable debug output}';

  protected $description = 'Update the WebKernel core bootstrap';

  private ModuleService $service;
  private bool $debug = false;

  public function handle(): int
  {
    $this->debug = (bool) $this->option('debug');

    $currentVersion = $this->getCurrentKernelVersion();

    if (!$currentVersion) {
      PromptHelper::error(self::VERSION_DETECTION_FAILED_MSG);
      return 1;
    }

    $this->line(sprintf(self::CURRENT_VERSION_MSG, $currentVersion));
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
      PromptHelper::info(sprintf(self::ALREADY_ON_VERSION_MSG, $version));
      return 0;
    }

    if ($version === $currentVersion) {
      PromptHelper::warning(sprintf(self::REINSTALLING_VERSION_MSG, $version));
    }

    $result = $this->executeUpdate($version, $createBackup);

    return $this->handleUpdateResult($result);
  }

  private function debug(string $message): void
  {
    if ($this->debug) {
      $this->line("[DEBUG] {$message}");
    }
  }

  private function getCurrentKernelVersion(): ?string
  {
    $bootstrapPath = base_path(self::BOOTSTRAP_APP_PATH);

    if (!file_exists($bootstrapPath)) {
      $this->debug("Bootstrap file not found: {$bootstrapPath}");
      return null;
    }

    $content = file_get_contents($bootstrapPath);

    if ($content === false) {
      $this->debug('Failed to read bootstrap file');
      return null;
    }

    if (preg_match(self::VERSION_PATTERN, $content, $matches)) {
      $this->debug("Detected version: {$matches[1]}");
      return $matches[1];
    }

    $this->debug('Version pattern not matched in bootstrap file');
    return null;
  }

  private function fetchReleases(GitHubProvider $provider): ?array
  {
    $includePreRelease = (bool) $this->option('pre-release');

    $this->newLine();
    $this->line(sprintf(self::FETCHING_RELEASES_MSG, self::KERNEL_REPO));

    try {
      $this->debug('Calling provider->fetchReleases()');
      $releases = $provider->fetchReleases(self::KERNEL_REPO, $includePreRelease);

      if (!$releases) {
        $this->debug('No releases returned from provider');
        return null;
      }

      $this->line(sprintf(self::FOUND_RELEASES_MSG, count($releases)));
      $this->debug('Releases: ' . json_encode(array_column($releases, 'tag_name')));

      return $releases;
    } catch (\Exception $e) {
      $this->debug('Exception in fetchReleases: ' . $e->getMessage());
      $this->debug('Stack trace: ' . $e->getTraceAsString());
      PromptHelper::error(sprintf(self::FETCH_FAILED_MSG, $e->getMessage()));
      return null;
    }
  }

  private function executeUpdate(string $version, bool $createBackup): array
  {
    $this->newLine();
    $this->line("Starting kernel update to {$version}...");

    try {
      $this->debug('Calling service->updateKernel()');
      $this->debug("Version: {$version}, Backup: " . ($createBackup ? 'yes' : 'no'));

      $result = $this->service->updateKernel($version, $createBackup);

      $this->debug('Update completed, result: ' . json_encode($result));

      return $result;
    } catch (\Exception $e) {
      $this->debug('Exception in executeUpdate: ' . $e->getMessage());
      $this->debug('Stack trace: ' . $e->getTraceAsString());

      return [
        'success' => false,
        'error' => $e->getMessage(),
      ];
    }
  }

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

  private function selectVersion(array $releases, string $currentVersion): ?string
  {
    if ($this->option('with-version')) {
      $requested = (string) $this->option('with-version');

      if (!$this->versionExists($releases, $requested)) {
        PromptHelper::error(sprintf(self::VERSION_NOT_FOUND_MSG, $requested));
        return null;
      }

      return $requested;
    }

    if ($this->option('latest')) {
      return $releases[0]['tag_name'];
    }

    $this->newLine();

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

  private function versionExists(array $releases, string $version): bool
  {
    foreach ($releases as $release) {
      if ($release['tag_name'] === $version) {
        return true;
      }
    }

    return false;
  }

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

    if ($this->debug) {
      $service->setVerbose(true);
      $service->setOutput($this->output);
    }

    return $service;
  }
}
