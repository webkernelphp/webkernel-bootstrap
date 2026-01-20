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
                            {--dry-run : Simulate without making changes}';

  /**
   * The console command description
   *
   * @var string
   */
  protected $description = 'Update the WebKernel core bootstrap';

  /**
   * Execute the console command
   *
   * @return int Exit code (0 = success, 1 = failure)
   */
  public function handle(): int
  {
    $config = new ConfigManager();
    $service = $this->createService();

    $createBackup = !$this->option('no-backup') && PromptHelper::confirm('Create backup before kernel update?', true);

    PromptHelper::warning('This will update the core WebKernel bootstrap');

    if (!PromptHelper::confirm('Continue with kernel update?', false)) {
      PromptHelper::info('Update cancelled');
      return 0;
    }

    $token = $this->option('token') ? (string) $this->option('token') : $config->getToken();
    $provider = new GitHubProvider($token, false);

    /** @var array<int, array{tag_name: string, name?: string, published_at?: string, prerelease?: bool}>|null $releases */
    $releases = PromptHelper::spin(
      fn() => $provider->fetchReleases('webkernelphp/bootstrap', (bool) $this->option('pre-release')),
      'Fetching kernel releases...',
    );

    if (!$releases || count($releases) === 0) {
      PromptHelper::error('No kernel releases found');
      return 1;
    }

    $version = $this->selectVersion($releases);
    if (!$version) {
      PromptHelper::error('No version selected');
      return 1;
    }

    /** @var array{success: bool, error?: string, dry_run?: bool, version?: string} $result */
    $result = PromptHelper::spin(
      fn() => $service->updateKernel($version, $createBackup),
      "Updating kernel to {$version}...",
    );

    if ($result['success']) {
      if (isset($result['dry_run'])) {
        PromptHelper::info('[DRY RUN] Would have updated kernel');
        return 0;
      }

      $this->newLine();
      $this->components->info('Kernel updated successfully!');
      $this->line("  Version: {$result['version']}");

      return 0;
    }

    PromptHelper::error($result['error'] ?? 'Kernel update failed');
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

    return PromptHelper::select('Select kernel version', $options, $releases[0]['tag_name']);
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
