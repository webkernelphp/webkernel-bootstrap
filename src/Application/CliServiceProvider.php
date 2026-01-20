<?php declare(strict_types=1);

namespace Webkernel;

use Illuminate\Support\ServiceProvider;
use Webkernel\Console\Commands\{InstallModuleCommand, ListModulesCommand, KernelUpdateCommand};

final class CliServiceProvider extends ServiceProvider
{
  /**
   * Bootstrap services.
   */
  public function boot(): void
  {
    if ($this->app->runningInConsole()) {
      $this->commands([InstallModuleCommand::class, ListModulesCommand::class, KernelUpdateCommand::class]);
    }
  }
}
