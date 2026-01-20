<?php declare(strict_types=1);

namespace Webkernel\Modules\Core\Contracts;

use Webkernel\Modules\Core\ValidationResult;

interface ModuleValidator
{
  public function validate(string $modulePath): ValidationResult;
}
