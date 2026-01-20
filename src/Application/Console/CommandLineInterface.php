<?php
declare(strict_types=1);

namespace Webkernel\Console;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

final class CommandLineInterface
{
  /**
   * Run the CLI kernel with argv rewriting for WebKernel aliases.
   *
   * @param array<int, string> $argv
   */
  public static function run(array $argv): int
  {
    /** @var Kernel $kernel */
    $kernel = app()->make(Kernel::class);

    /** @var array<string, string> $aliases */
    $aliases = [
      'require-module' => 'webkernel:install',
      'install' => 'webkernel:install',
      'list-modules' => 'webkernel:list',
      'list' => 'webkernel:list',
      'kernel-update' => 'webkernel:kernel-update',
      'update' => 'webkernel:kernel-update',
    ];

    if (isset($argv[1]) && isset($aliases[$argv[1]])) {
      $argv[1] = $aliases[$argv[1]];
    }

    $input = new ArgvInput($argv);
    $output = new ConsoleOutput();

    if (!isset($argv[1]) || $argv[1] === 'list' || $argv[1] === '--help' || $argv[1] === '-h') {
      $kernel->bootstrap();

      $commands = $kernel->all();

      $webkernelCommandNames = array_values(
        array_filter(array_keys($commands), static fn(string $name): bool => str_starts_with($name, 'webkernel:')),
      );

      $output->writeln('');
      $output->writeln('<fg=green>WebKernel Module Manager</>');
      $output->writeln('');
      $output->writeln('<fg=yellow>Available Commands:</>');

      foreach ($webkernelCommandNames as $commandName) {
        $command = $commands[$commandName];

        $description = $command->getDescription();
        $aliasNames = array_keys($aliases, $commandName);

        if ($aliasNames !== []) {
          $aliasDisplay = implode(', ', array_map(static fn(string $a): string => "<fg=cyan>{$a}</>", $aliasNames));
          $output->writeln("  <fg=green>{$commandName}</> ({$aliasDisplay})");
        } else {
          $output->writeln("  <fg=green>{$commandName}</>");
        }

        if ($description !== '') {
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
