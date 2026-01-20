<?php declare(strict_types=1);

namespace Webkernel\Modules\Core;

use Webkernel\Modules\Core\Contracts\ModuleValidator;
use Webkernel\Arcanes\ModuleMetadata;
use Illuminate\Support\Facades\File;

final class WebKernelModuleValidator implements ModuleValidator
{
  public function validate(string $modulePath): ValidationResult
  {
    $errors = [];
    $warnings = [];

    if (!is_dir($modulePath)) {
      $errors[] = "Module directory does not exist: {$modulePath}";
      return new ValidationResult(false, $errors, $warnings);
    }

    $moduleFile = $this->findModuleFile($modulePath);

    if ($moduleFile === null) {
      $errors[] = 'No valid *Module.php file found in module root';
      return new ValidationResult(false, $errors, $warnings);
    }

    $metadata = ModuleMetadata::fromModuleFile($moduleFile);

    if ($metadata === null) {
      $errors[] = "Failed to parse module configuration from {$moduleFile}";
      return new ValidationResult(false, $errors, $warnings);
    }

    if ($metadata->installPath === '') {
      $warnings[] = 'Module does not declare installPath() - installation location undefined';
    }

    if ($metadata->namespace === '') {
      $warnings[] = 'Module does not declare namespace in installPath()';
    }

    $content = File::get($moduleFile);

    if (!str_contains($content, 'extends WebkernelApp')) {
      $errors[] = 'Module class must extend WebkernelApp';
    }

    if (!str_contains($content, 'function configureModule()')) {
      $errors[] = 'Module class must implement configureModule() method';
    }

    return new ValidationResult(empty($errors), $errors, $warnings);
  }

  private function findModuleFile(string $modulePath): ?string
  {
    $files = File::glob($modulePath . '/*Module.php');

    foreach ($files as $file) {
      if (is_file($file)) {
        return $file;
      }
    }

    return null;
  }
}
