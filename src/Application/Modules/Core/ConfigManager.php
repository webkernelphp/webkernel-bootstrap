<?php declare(strict_types=1);

namespace Webkernel\Modules\Core;

use Illuminate\Support\Facades\File;
use Webkernel\CryptData;

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

  public function getGithubToken(string $owner, ?string $repo = null): ?string
  {
    // 1. Chercher d'abord le token spécifique au repo
    if ($repo && isset($this->data['github_tokens'][$owner]['repos'][$repo])) {
      return CryptData::decrypt($this->data['github_tokens'][$owner]['repos'][$repo]);
    }

    // 2. Sinon chercher le token général de l'owner
    if (isset($this->data['github_tokens'][$owner]['global'])) {
      return CryptData::decrypt($this->data['github_tokens'][$owner]['global']);
    }

    return null;
  }

  public function saveGithubToken(string $owner, string $token, ?string $repo = null): void
  {
    File::ensureDirectoryExists(dirname($this->configPath));

    // Initialisation sécurisée des niveaux de tableaux
    if (!isset($this->data['github_tokens']) || !is_array($this->data['github_tokens'])) {
      $this->data['github_tokens'] = [];
    }

    if (!isset($this->data['github_tokens'][$owner]) || !is_array($this->data['github_tokens'][$owner])) {
      $this->data['github_tokens'][$owner] = [
        'global' => null,
        'repos' => [],
      ];
    }

    if ($repo) {
      // S'assurer que la clé 'repos' existe
      if (!isset($this->data['github_tokens'][$owner]['repos'])) {
        $this->data['github_tokens'][$owner]['repos'] = [];
      }
      $this->data['github_tokens'][$owner]['repos'][$repo] = CryptData::encrypt($token);
    } else {
      $this->data['github_tokens'][$owner]['global'] = CryptData::encrypt($token);
    }

    $this->save();
  }

  private function save(): void
  {
    $this->data['updated_at'] = now()->toIso8601String();

    $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    File::put($this->configPath, $json);
    File::chmod($this->configPath, 0600);
  }

  public function getToken(?string $owner = 'webkernelphp', ?string $repo = null): ?string
  {
    return $this->getGithubToken($owner, $repo);
  }
}
