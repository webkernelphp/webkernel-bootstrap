<?php
declare(strict_types=1);

namespace WebKernel;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

final class CommandLineInterface
{
  public static function run(array $argv): int
  {
    $kernel = app()->make(Kernel::class);

    // Command aliases mapping
    $aliases = [
      'require-module' => 'webkernel:install',
      'install' => 'webkernel:install',
      'list-modules' => 'webkernel:list',
      'list' => 'webkernel:list',
      'kernel-update' => 'webkernel:kernel-update',
      'update' => 'webkernel:kernel-update',
    ];

    // Rewrite argv for aliases
    if (isset($argv[1]) && isset($aliases[$argv[1]])) {
      $argv[1] = $aliases[$argv[1]];
    }

    $input = new ArgvInput($argv);
    $output = new ConsoleOutput();

    // Keeping custom filtering and display block
    if (!isset($argv[1]) || $argv[1] === 'list' || $argv[1] === '--help' || $argv[1] === '-h') {
      $kernel->bootstrap();
      $commands = $kernel->all();

      $webkernelCommands = array_filter(array_keys($commands), fn($name) => str_starts_with($name, 'webkernel:'));

      $output->writeln('');
      $output->writeln('<fg=green>WebKernel Module Manager</>');
      $output->writeln('');
      $output->writeln('<fg=yellow>Available Commands:</>');

      foreach ($webkernelCommands as $commandName) {
        $command = $commands[$commandName];
        $description = $command->getDescription();
        $aliasNames = array_keys($aliases, $commandName);

        $displayName = $commandName;
        if (!empty($aliasNames)) {
          $aliasDisplay = implode(', ', array_map(fn($a) => "<fg=cyan>{$a}</>", $aliasNames));
          $output->writeln("  <fg=green>{$displayName}</> ({$aliasDisplay})");
        } else {
          $output->writeln("  <fg=green>{$displayName}</>");
        }

        if ($description) {
          $output->writeln("    {$description}");
        }
        $output->writeln('');
      }

      $output->writeln('<fg=yellow>Usage:</>');
      $output->writeln('  ./webkernel <command> [options] [arguments]');
      $output->writeln('');
      $output->writeln('<fg=yellow>Examples:</>');
      $output->writeln('  ./webkernel require-module acme/blog --latest');
      $output->writeln('  ./webkernel install wk://crm');
      $output->writeln('  ./webkernel list-modules');
      $output->writeln('  ./webkernel kernel-update');
      $output->writeln('');

      return 0;
    }

    $status = $kernel->handle($input, $output);
    $kernel->terminate($input, $status);
    return $status;
  }
}
