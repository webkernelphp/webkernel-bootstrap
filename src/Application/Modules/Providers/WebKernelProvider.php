<?php declare(strict_types=1);

namespace Webkernel\Modules\Providers;

use Webkernel\Modules\Core\Contracts\SourceProvider;
use Webkernel\Modules\Exceptions\NetworkException;
use Webkernel\Modules\Exceptions\ModuleException;
use Webkernel\Modules\Exceptions\IntegrityException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use ZipArchive;

final class WebKernelProvider implements SourceProvider
{
  private const string API_BASE = 'https://webkernelphp.com/api';

  public function __construct(private ?string $token = null) {}

  public function supports(string $identifier): bool
  {
    return str_starts_with($identifier, 'webkernelphp.com/') || preg_match('#^wk://(.+)$#', $identifier) === 1;
  }

  public function fetchReleases(string $identifier, bool $includePreReleases = false): ?array
  {
    $module = $this->parseIdentifier($identifier);
    $url = sprintf('%s/modules/%s/releases', self::API_BASE, $module);

    if ($includePreReleases) {
      $url .= '?include_prereleases=1';
    }

    $request = Http::withHeaders([
      'Accept' => 'application/json',
    ]);

    if ($this->token) {
      $request->withToken($this->token);
    }

    $response = $request->get($url);

    if ($response->failed()) {
      throw new NetworkException('Failed to contact WebKernel registry');
    }

    $data = $response->json();

    if (isset($data['error'])) {
      if ($data['error'] === 'authentication_required') {
        throw new ModuleException('This module requires authentication. Use --token=YOUR_TOKEN');
      }
      throw new ModuleException($data['error']);
    }

    return $data['releases'] ?? null;
  }

  public function downloadRelease(array $release, string $targetDir): bool
  {
    $request = Http::timeout(120);

    if ($this->token) {
      $request->withToken($this->token);
    }

    $response = $request->get($release['download_url']);

    if ($response->failed()) {
      throw new NetworkException('Download failed from WebKernel registry');
    }

    $content = $response->body();

    if (!$this->verifyChecksum($content, $release)) {
      throw new IntegrityException('Checksum verification failed');
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'wk-');
    file_put_contents($tempFile, $content);

    File::ensureDirectoryExists($targetDir);

    $zip = new ZipArchive();

    if ($zip->open($tempFile) !== true) {
      unlink($tempFile);
      throw new ModuleException('Invalid archive');
    }

    $zip->extractTo($targetDir);
    $zip->close();
    unlink($tempFile);

    return true;
  }

  public function verifyChecksum(string $content, array $release): bool
  {
    if (!isset($release['sha256'])) {
      return true;
    }

    $computed = hash('sha256', $content);

    return $computed === $release['sha256'];
  }

  private function parseIdentifier(string $id): string
  {
    if (preg_match('#^wk://(.+)$#', $id, $m)) {
      return $m[1];
    }

    if (str_starts_with($id, 'webkernelphp.com/')) {
      return substr($id, 17);
    }

    return $id;
  }
}
