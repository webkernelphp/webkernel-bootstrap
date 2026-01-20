<?php declare(strict_types=1);

namespace Webkernel\Modules\Core;

final class ValidationResult
{
  public function __construct(
    public readonly bool $isValid,
    public readonly array $errors = [],
    public readonly array $warnings = [],
  ) {}
}
