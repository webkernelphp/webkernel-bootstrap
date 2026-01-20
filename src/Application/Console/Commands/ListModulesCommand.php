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
 * List all installed WebKernel modules
 *
 * @package Webkernel\Modules\Cli
 */
final class ListModulesCommand extends Command
{
  /**
   * The name and signature of the console command
   *
   * @var string
   */
  protected $signature = 'webkernel:list';

  /**
   * The console command description
   *
   * @var string
   */
  protected $description = 'List all installed WebKernel modules';

  /**
   * Execute the console command
   *
   * @return int Exit code (0 = success)
   */
  public function handle(): int
  {
    $service = $this->createService();

    /** @var array<int, array{vendor: string, module: string, version: string, name: string, namespace: string}> $modules */
    $modules = PromptHelper::spin(fn() => $service->listInstalledModules(), 'Scanning installed modules...');

    if (empty($modules)) {
      PromptHelper::info('No modules installed');
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

  /**
   * Create ModuleService instance
   *
   * @return ModuleService
   */
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
