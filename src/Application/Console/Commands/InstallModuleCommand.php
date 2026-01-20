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
 * Install a WebKernel module from various sources
 *
 * @package Webkernel\Modules\Cli
 */
final class InstallModuleCommand extends Command
{
  /**
   * The name and signature of the console command
   *
   * @var string
   */
  protected $signature = 'webkernel:install
                            {source : Module source (owner/repo, wk://module, etc)}
                            {--with-version= : Specific version to install}
                            {--latest : Install latest version}
                            {--token= : Authentication token (required for private repos)}
                            {--save-token : Save token to config}
                            {--no-backup : Skip backup creation}
                            {--no-hooks : Skip hook execution}
                            {--no-validate : Skip validation}
                            {--insecure : Disable SSL verification}
                            {--pre-release : Include pre-releases}
                            {--dry-run : Simulate without making changes}';

  /**
   * The console command description
   *
   * @var string
   */
  protected $description = 'Install a WebKernel module from a source provider (supports private GitHub repos)';

  /**
   * Execute the console command
   *
   * @return int Exit code (0 = success, 1 = failure)
   */
  public function handle(): int
  {
    $config = new ConfigManager();
    $service = $this->createService();

    /** @var string $identifier */
    $identifier = $this->argument('source');

    // Handle token saving
    if ($this->option('token') && $this->option('save-token')) {
      $config->saveToken((string) $this->option('token'));
      $this->components->info('Token saved successfully');
    }

    // Show insecure warning if needed
    if ($this->option('insecure')) {
      $this->showInsecureWarning();
    }

    // Confirm backup creation
    $createBackup = !$this->option('no-backup') && PromptHelper::confirm('Create backup before installation?', true);

    // Create provider and fetch releases
    $provider = $this->createProvider($identifier, $config);

    /** @var array<int, array{tag_name: string, name?: string, published_at?: string, prerelease?: bool}>|null $releases */
    $releases = PromptHelper::spin(
      fn() => $provider->fetchReleases($identifier, (bool) $this->option('pre-release')),
      "Fetching releases for {$identifier}...",
    );

    if (!$releases || count($releases) === 0) {
      PromptHelper::error('No releases found');
      return 1;
    }

    // Select version to install
    $version = $this->selectVersion($releases);
    if (!$version) {
      PromptHelper::error('No version selected');
      return 1;
    }

    // Install the module
    /** @var array{success: bool, error?: string, dry_run?: bool, path?: string, version?: string, namespace?: string, install_path?: string} $result */
    $result = PromptHelper::spin(
      fn() => $service->installModule($identifier, $version, $createBackup),
      "Installing {$identifier} version {$version}...",
    );

    if ($result['success']) {
      if (isset($result['dry_run'])) {
        PromptHelper::info('[DRY RUN] Would have installed module');
        return 0;
      }

      $this->newLine();
      $this->components->info('Module installed successfully!');
      $this->table(
        ['Property', 'Value'],
        [
          ['Path', $result['path'] ?? 'N/A'],
          ['Version', $result['version'] ?? 'N/A'],
          ['Namespace', $result['namespace'] ?? 'N/A'],
          ['Install Path', $result['install_path'] ?? 'N/A'],
        ],
      );
      $this->newLine();
      $this->components->info('Run the following to rebuild the manifest:');
      $this->line('  php bootstrap/Application/Arcanes/BuildManifest.php');

      return 0;
    }

    PromptHelper::error($result['error'] ?? 'Installation failed');
    return 1;
  }

  /**
   * Select version from available releases
   *
   * @param array<int, array{tag_name: string, name?: string, published_at?: string, prerelease?: bool}> $releases
   * @return string|null Selected version tag
   */
  private function selectVersion(array $releases): ?string
  {
    if ($this->option('with-version')) {
      return (string) $this->option('with-version');
    }

    if ($this->option('latest')) {
      return $releases[0]['tag_name'];
    }

    /** @var array<string, string> $options */
    $options = [];
    foreach ($releases as $release) {
      $prerelease = $release['prerelease'] ?? false ? ' [PRE-RELEASE]' : '';
      $published = substr($release['published_at'] ?? '', 0, 10);
      $label = sprintf('%s - %s (%s)%s', $release['tag_name'], $release['name'] ?? '', $published, $prerelease);
      $options[$release['tag_name']] = $label;
    }

    return PromptHelper::select('Select release to install', $options, $releases[0]['tag_name']);
  }

  /**
   * Display insecure mode warning and request confirmation
   *
   * @return void
   */
  private function showInsecureWarning(): void
  {
    PromptHelper::warning('SSL verification disabled - INSECURE MODE ACTIVE');
    PromptHelper::warning('This exposes you to man-in-the-middle attacks');
    PromptHelper::warning('DO NOT USE IN PRODUCTION');

    if (!PromptHelper::confirm('Continue anyway?', false)) {
      PromptHelper::info('Operation cancelled');
      exit(0);
    }
  }

  /**
   * Create appropriate provider based on identifier
   *
   * @param string $identifier Module identifier
   * @param ConfigManager $config Configuration manager
   * @return GitHubProvider|WebKernelProvider
   */
  private function createProvider(string $identifier, ConfigManager $config): GitHubProvider|WebKernelProvider
  {
    $token = $this->option('token') ? (string) $this->option('token') : $config->getToken();
    $insecure = (bool) $this->option('insecure');

    if (str_contains($identifier, 'webkernelphp.com') || str_starts_with($identifier, 'wk://')) {
      return new WebKernelProvider($token);
    }

    return new GitHubProvider($token, $insecure);
  }

  /**
   * Create and configure ModuleService instance
   *
   * @return ModuleService
   */
  private function createService(): ModuleService
  {
    $lock = new LockManager();
    $backup = new BackupManager();
    $composer = new ComposerManager();
    $validator = new WebKernelModuleValidator();
    $hookExecutor = new HookExecutor();

    $service = new ModuleService($lock, $backup, $composer, $validator, $hookExecutor);

    $config = new ConfigManager();
    $token = $this->option('token') ? (string) $this->option('token') : $config->getToken();
    $insecure = (bool) $this->option('insecure');

    $service->addProvider(new GitHubProvider($token, $insecure));
    $service->addProvider(new WebKernelProvider($token));

    if ($this->option('no-hooks')) {
      $service->setExecuteHooks(false);
    }

    if ($this->option('no-validate')) {
      $service->setValidateModules(false);
    }

    if ($this->option('dry-run')) {
      $service->setDryRun(true);
    }

    return $service;
  }
}
