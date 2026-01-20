<?php declare(strict_types=1);

namespace Webkernel\Modules\Providers;

use Webkernel\Modules\Core\Contracts\SourceProvider;
use Webkernel\Modules\Exceptions\NetworkException;
use Webkernel\Modules\Exceptions\ModuleException;
use Webkernel\Modules\Exceptions\IntegrityException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use ZipArchive;

final class GitHubProvider implements SourceProvider
{
  private const string API_BASE = 'https://api.github.com';

  public function __construct(private ?string $token = null, private bool $insecure = false) {}

  public function supports(string $identifier): bool
  {
    return preg_match('#^([^/]+)/([^/]+)$|github\.com/([^/]+)/([^/]+)#i', $identifier) === 1;
  }

  public function fetchReleases(string $identifier, bool $includePreReleases = false): ?array
  {
    [$owner, $repo] = $this->parseIdentifier($identifier);

    if (!$owner || !$repo) {
      return null;
    }

    $url = sprintf('%s/repos/%s/%s/releases', self::API_BASE, $owner, $repo);

    $request = Http::withHeaders([
      'Accept' => 'application/vnd.github.v3+json',
    ]);

    if ($this->token) {
      $request->withToken($this->token);
    }

    if ($this->insecure) {
      $request->withoutVerifying();
    }

    $response = $request->get($url);

    if ($response->status() === 404) {
      throw new NetworkException("Repository {$owner}/{$repo} not found");
    }

    if ($response->failed()) {
      throw new NetworkException("GitHub API error: HTTP {$response->status()}");
    }

    $data = $response->json();

    if (!is_array($data)) {
      return null;
    }

    return array_filter($data, function ($release) use ($includePreReleases) {
      if ($release['draft'] ?? false) {
        return false;
      }

      if (!$includePreReleases && ($release['prerelease'] ?? false)) {
        return false;
      }

      return true;
    });
  }

  public function downloadRelease(array $release, string $targetDir): bool
  {
    $request = Http::timeout(120);

    if ($this->token) {
      $request->withToken($this->token);
    }

    if ($this->insecure) {
      $request->withoutVerifying();
    }

    $response = $request->get($release['zipball_url']);

    if ($response->failed()) {
      throw new NetworkException('Download failed: HTTP ' . $response->status());
    }

    $zipContent = $response->body();

    if (isset($release['assets']) && !empty($release['assets'])) {
      foreach ($release['assets'] as $asset) {
        if (str_ends_with($asset['name'], '.sha256')) {
          if (!$this->verifyChecksum($zipContent, $release)) {
            throw new IntegrityException('Checksum verification failed');
          }
          break;
        }
      }
    }

    return $this->extractArchive($zipContent, $targetDir);
  }

  public function verifyChecksum(string $content, array $release): bool
  {
    if (!isset($release['assets'])) {
      return true;
    }

    foreach ($release['assets'] as $asset) {
      if (str_ends_with($asset['name'], '.sha256')) {
        $checksumUrl = $asset['browser_download_url'];
        $expectedChecksum = trim(Http::get($checksumUrl)->body());
        $actualChecksum = hash('sha256', $content);

        return $expectedChecksum === $actualChecksum;
      }
    }

    return true;
  }

  private function parseIdentifier(string $id): array
  {
    if (preg_match('#github\.com/([^/]+)/([^/]+?)(?:\.git)?$#i', $id, $m)) {
      return [$m[1], $m[2]];
    }

    if (preg_match('#^([^/]+)/([^/]+)$#', $id, $m)) {
      return [$m[1], $m[2]];
    }

    return [null, null];
  }

  private function extractArchive(string $zipContent, string $targetDir): bool
  {
    $tempFile = tempnam(sys_get_temp_dir(), 'wk-');
    file_put_contents($tempFile, $zipContent);

    File::ensureDirectoryExists($targetDir);

    $zip = new ZipArchive();

    if ($zip->open($tempFile) !== true) {
      unlink($tempFile);
      throw new ModuleException('Invalid ZIP archive');
    }

    $zip->extractTo($targetDir);
    $zip->close();
    unlink($tempFile);

    $dirs = File::directories($targetDir);

    if (count($dirs) === 1) {
      $this->flattenDirectory($dirs[0], $targetDir);
    }

    return true;
  }

  private function flattenDirectory(string $source, string $dest): void
  {
    $files = File::allFiles($source);
    $directories = File::directories($source);

    foreach ($files as $file) {
      $relativePath = str_replace($source . '/', '', $file->getPathname());
      $targetPath = $dest . '/' . $relativePath;

      File::ensureDirectoryExists(dirname($targetPath));
      File::move($file->getPathname(), $targetPath);
    }

    File::deleteDirectory($source);
  }
}
