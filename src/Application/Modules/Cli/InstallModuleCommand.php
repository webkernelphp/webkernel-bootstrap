<?php declare(strict_types=1);

namespace Webkernel\Modules\Cli;

use Illuminate\Console\Command;
use Webkernel\Modules\Services\ModuleService;
use Webkernel\Modules\Managers\{LockManager, BackupManager, ComposerManager};
use Webkernel\Modules\Core\{Config, WebKernelModuleValidator, ConfigManager};
use Webkernel\Modules\Hooks\HookExecutor;
use Webkernel\Modules\Providers\{GitHubProvider, WebKernelProvider};
use Illuminate\Support\Facades\File;
use function Laravel\Prompts\{confirm, select, warning, error, info, spin};

/**
 * Install a WebKernel module
 */
final class InstallModuleCommand extends Command
{
  protected $signature = 'webkernel:install
                            {source : Module source (owner/repo, wk://module, etc)}
                            {--with-version= : Specific version to install}
                            {--latest : Install latest version}
                            {--token= : Authentication token}
                            {--save-token : Save token to config}
                            {--no-backup : Skip backup creation}
                            {--no-hooks : Skip hook execution}
                            {--no-validate : Skip validation}
                            {--insecure : Disable SSL verification}
                            {--pre-release : Include pre-releases}
                            {--dry-run : Simulate without making changes}';

  protected $description = 'Install a WebKernel module from a source provider';

  public function handle(): int
  {
    $config = new ConfigManager();
    $service = $this->createService();

    $identifier = $this->argument('source');

    if ($this->option('token') && $this->option('save-token')) {
      $config->saveToken($this->option('token'));
      $this->components->info('Token saved successfully');
    }

    if ($this->option('insecure')) {
      $this->showInsecureWarning();
    }

    $createBackup = !$this->option('no-backup') && confirm('Create backup before installation?', default: true);

    $provider = $this->createProvider($identifier, $config);

    $releases = spin(
      fn() => $provider->fetchReleases($identifier, $this->option('pre-release')),
      "Fetching releases for {$identifier}...",
    );

    if (!$releases || count($releases) === 0) {
      error('No releases found');
      return 1;
    }

    $version = $this->selectVersion($releases);

    if (!$version) {
      error('No version selected');
      return 1;
    }

    $result = spin(
      fn() => $service->installModule($identifier, $version, $createBackup),
      "Installing {$identifier} version {$version}...",
    );

    if ($result['success']) {
      if (isset($result['dry_run'])) {
        info('[DRY RUN] Would have installed module');
        return 0;
      }

      $this->newLine();
      $this->components->info('Module installed successfully!');
      $this->table(
        ['Property', 'Value'],
        [
          ['Path', $result['path']],
          ['Version', $result['version']],
          ['Namespace', $result['namespace']],
          ['Install Path', $result['install_path']],
        ],
      );

      $this->newLine();
      $this->components->info('Run the following to rebuild the manifest:');
      $this->line('  php bootstrap/Application/Arcanes/BuildManifest.php');

      return 0;
    }

    error($result['error'] ?? 'Installation failed');
    return 1;
  }

  private function selectVersion(array $releases): ?string
  {
    if ($this->option('version')) {
      return $this->option('version');
    }

    if ($this->option('latest')) {
      return $releases[0]['tag_name'];
    }

    $options = [];
    foreach ($releases as $release) {
      $prerelease = $release['prerelease'] ?? false ? ' [PRE-RELEASE]' : '';
      $published = substr($release['published_at'] ?? '', 0, 10);

      $label = sprintf('%s - %s (%s)%s', $release['tag_name'], $release['name'] ?? '', $published, $prerelease);

      $options[$release['tag_name']] = $label;
    }

    return select(label: 'Select release to install', options: $options, default: $releases[0]['tag_name']);
  }

  private function showInsecureWarning(): void
  {
    warning('SSL verification disabled - INSECURE MODE ACTIVE');
    warning('This exposes you to man-in-the-middle attacks');
    warning('DO NOT USE IN PRODUCTION');

    if (!confirm('Continue anyway?', default: false)) {
      info('Operation cancelled');
      exit(0);
    }
  }

  private function createProvider(string $identifier, ConfigManager $config): mixed
  {
    $token = $this->option('token') ?? $config->getToken();
    $insecure = $this->option('insecure') ?? false;

    if (str_contains($identifier, 'webkernelphp.com') || str_starts_with($identifier, 'wk://')) {
      return new WebKernelProvider($token);
    }

    return new GitHubProvider($token, $insecure);
  }

  private function createService(): ModuleService
  {
    $lock = new LockManager();
    $backup = new BackupManager();
    $composer = new ComposerManager();
    $validator = new WebKernelModuleValidator();
    $hookExecutor = new HookExecutor();

    $service = new ModuleService($lock, $backup, $composer, $validator, $hookExecutor);

    $config = new ConfigManager();
    $token = $this->option('token') ?? $config->getToken();
    $insecure = $this->option('insecure') ?? false;

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

/**
 * List installed WebKernel modules
 */
final class ListModulesCommand extends Command
{
  protected $signature = 'webkernel:list';
  protected $description = 'List all installed WebKernel modules';

  public function handle(): int
  {
    $service = $this->createService();

    $modules = spin(fn() => $service->listInstalledModules(), 'Scanning installed modules...');

    if (empty($modules)) {
      info('No modules installed');
      return 0;
    }

    $this->table(
      ['Vendor', 'Module', 'Version', 'Name', 'Namespace'],
      array_map(fn($m) => [$m['vendor'], $m['module'], $m['version'], $m['name'], $m['namespace']], $modules),
    );

    $this->newLine();
    $this->info('Total: ' . count($modules) . ' module(s)');

    return 0;
  }

  private function createService(): ModuleService
  {
    return new ModuleService(
      new LockManager(),
      new BackupManager(),
      new ComposerManager(),
      new WebKernelModuleValidator(),
      new HookExecutor(),
    );
  }
}

/**
 * Update the WebKernel core
 */
final class KernelUpdateCommand extends Command
{
  protected $signature = 'webkernel:kernel-update
                            {--with-version= : Specific version to install}
                            {--latest : Install latest version}
                            {--token= : Authentication token}
                            {--no-backup : Skip backup creation}
                            {--no-hooks : Skip hook execution}
                            {--pre-release : Include pre-releases}
                            {--dry-run : Simulate without making changes}';

  protected $description = 'Update the WebKernel core bootstrap';

  public function handle(): int
  {
    $config = new ConfigManager();
    $service = $this->createService();

    $createBackup = !$this->option('no-backup') && confirm('Create backup before kernel update?', default: true);

    warning('This will update the core WebKernel bootstrap');

    if (!confirm('Continue with kernel update?', default: false)) {
      info('Update cancelled');
      return 0;
    }

    $provider = new GitHubProvider($this->option('token') ?? $config->getToken(), false);

    $releases = spin(
      fn() => $provider->fetchReleases('webkernelphp/bootstrap', $this->option('pre-release')),
      'Fetching kernel releases...',
    );

    if (!$releases || count($releases) === 0) {
      error('No kernel releases found');
      return 1;
    }

    $version = $this->selectVersion($releases);

    if (!$version) {
      error('No version selected');
      return 1;
    }

    $result = spin(fn() => $service->updateKernel($version, $createBackup), "Updating kernel to {$version}...");

    if ($result['success']) {
      if (isset($result['dry_run'])) {
        info('[DRY RUN] Would have updated kernel');
        return 0;
      }

      $this->newLine();
      $this->components->info('Kernel updated successfully!');
      $this->line("  Version: {$result['version']}");

      return 0;
    }

    error($result['error'] ?? 'Kernel update failed');
    return 1;
  }

  private function selectVersion(array $releases): ?string
  {
    if ($this->option('version')) {
      return $this->option('version');
    }

    if ($this->option('latest')) {
      return $releases[0]['tag_name'];
    }

    $options = [];
    foreach ($releases as $release) {
      $prerelease = $release['prerelease'] ?? false ? ' [PRE-RELEASE]' : '';
      $published = substr($release['published_at'] ?? '', 0, 10);

      $label = sprintf('%s - %s (%s)%s', $release['tag_name'], $release['name'] ?? '', $published, $prerelease);

      $options[$release['tag_name']] = $label;
    }

    return select(label: 'Select kernel version', options: $options, default: $releases[0]['tag_name']);
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
    $token = $this->option('token') ?? $config->getToken();

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
