<?php declare(strict_types=1);
namespace Webkernel\DTOs;
final class CommandResult
{
  public function __construct(
    public readonly bool $success,
    public readonly string $output,
    public readonly string $error,
  ) {}

  public function status(): string
  {
    return $this->success ? 'ok' : 'error';
  }

  public function message(): string
  {
    return $this->success ? $this->output : $this->error;
  }

  public function toArray(): array
  {
    return [
      'success' => $this->success,
      'output' => $this->output,
      'error' => $this->error,
      'status' => $this->status(),
      'message' => $this->message(),
    ];
  }
}
