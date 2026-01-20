<?php declare(strict_types=1);

namespace Webkernel\Modules\Core;

use Webkernel\Modules\Exceptions\ModuleException;
use Illuminate\Support\Facades\File;

final class ConfigManager
{
  private string $configPath;
  private array $data = [];

  public function __construct()
  {
    $this->configPath = base_path(Config::CONFIG_FILE);
    $this->load();
  }

  private function load(): void
  {
    if (!file_exists($this->configPath)) {
      $this->data = [];
      return;
    }

    $json = File::get($this->configPath);
    $data = json_decode($json, true);

    $this->data = is_array($data) ? $data : [];
  }

  public function getToken(): ?string
  {
    return $this->data['token'] ?? null;
  }

  public function saveToken(string $token): void
  {
    File::ensureDirectoryExists(dirname($this->configPath));

    $this->data['token'] = $token;
    $this->data['updated_at'] = now()->toIso8601String();

    $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    File::put($this->configPath, $json);
    File::chmod($this->configPath, 0600);
  }

  public function get(string $key, mixed $default = null): mixed
  {
    return $this->data[$key] ?? $default;
  }

  public function set(string $key, mixed $value): void
  {
    $this->data[$key] = $value;
    $this->save();
  }

  private function save(): void
  {
    File::ensureDirectoryExists(dirname($this->configPath));

    $this->data['updated_at'] = now()->toIso8601String();

    $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    File::put($this->configPath, $json);
    File::chmod($this->configPath, 0600);
  }
}
