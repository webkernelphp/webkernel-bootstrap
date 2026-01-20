<?php declare(strict_types=1);
namespace Webkernel\Modules\Providers;

use Webkernel\Modules\Core\Contracts\SourceProvider;
use Webkernel\Modules\Exceptions\{NetworkException, IntegrityException};
use Illuminate\Support\Facades\{Http, File};
use Illuminate\Http\Client\Response;
use Webkernel\Console\PromptHelper;
use Webkernel\Modules\Core\ConfigManager;
use ZipArchive;

final class GitHubProvider implements SourceProvider
{
  private const string API_BASE = 'https://api.github.com';
  private const int DOWNLOAD_TIMEOUT = 600;
  private const int CONNECT_TIMEOUT = 30;
  private const int MAX_REDIRECTS = 10;
  private const array CURL_PROGRESS_THRESHOLDS = [10, 25, 50, 75, 90];

  private ConfigManager $config;
  private bool $tokenLoadedFromConfig = false;

  public function __construct(private ?string $token = null, private bool $insecure = false)
  {
    $this->config = new ConfigManager();
  }

  public function getToken(): ?string
  {
    return $this->token;
  }

  public function supports(string $identifier): bool
  {
    return preg_match('#^([^/]+)/([^/]+)$|github\.com/([^/]+)/([^/]+)#i', $identifier) === 1;
  }

  public function fetchReleases(string $identifier, bool $includePreReleases = false): ?array
  {
    [$owner, $repo] = $this->parseIdentifier($identifier);

    if (!$this->token) {
      $loaded = $this->config->getGithubToken($owner, $repo);
      if (is_string($loaded) && trim($loaded) !== '') {
        $this->token = trim($loaded);
        $this->tokenLoadedFromConfig = true;
      }
    }

    try {
      $this->detectRepositoryVisibility($owner, $repo);
    } catch (NetworkException $e) {
      PromptHelper::error($e->getMessage());
      return null;
    }

    return $this->executeFetch($owner, $repo, $includePreReleases);
  }

  private function detectRepositoryVisibility(string $owner, string $repo): bool
  {
    $repoUrl = sprintf('%s/repos/%s/%s', self::API_BASE, $owner, $repo);

    $anonRequest = Http::withHeaders([
      'Accept' => 'application/vnd.github+json',
      'X-GitHub-Api-Version' => '2022-11-28',
      'User-Agent' => 'WebKernel-Installer',
    ])->timeout(self::CONNECT_TIMEOUT);

    if ($this->insecure) {
      $anonRequest = $anonRequest->withoutVerifying();
    }

    /** @var Response $anonResponse */
    $anonResponse = $anonRequest->get($repoUrl);

    if ($anonResponse->successful()) {
      return true;
    }

    if ($anonResponse->status() === 404) {
      $confirmed = PromptHelper::confirm(
        label: "Repository {$owner}/{$repo} returned 404. It could be private or non-existent. Are you sure it exists?",
        default: false,
      );

      if (!$confirmed) {
        throw new NetworkException("Repository {$owner}/{$repo} not confirmed by user.");
      }

      if (!$this->token || trim($this->token) === '') {
        $this->askForTokenInteractively($owner, $repo);
      }

      $authResponse = $this->authenticatedRequest($repoUrl);

      if ($authResponse->successful()) {
        return true;
      }

      if ($authResponse->status() === 404) {
        $shouldRetry =
          $this->tokenLoadedFromConfig ||
          PromptHelper::confirm(
            label: 'Token cannot access this repository (404). Try a different token?',
            default: true,
          );

        if ($shouldRetry) {
          $this->showTokenHelp($owner, $repo);
          $this->askForTokenInteractively($owner, $repo);

          /** @var Response $retry */
          $retry = $this->authenticatedRequest($repoUrl);

          if ($retry->successful()) {
            return true;
          }

          if ($retry->status() === 404) {
            throw new NetworkException(
              "Still cannot access {$owner}/{$repo} with new token. " .
                "If using fine-grained token: go to token settings and add this repository to 'Repository access'. " .
                "Or use a classic token with 'repo' scope instead.",
            );
          }

          throw new NetworkException(
            "Failed to access repository. Status: {$retry->status()}. Response: {$retry->body()}",
          );
        }

        throw new NetworkException("Cannot access {$owner}/{$repo} with provided token. Check token permissions.");
      }

      throw new NetworkException(
        "Failed to access repository. Status: {$authResponse->status()}. Response: {$authResponse->body()}",
      );
    }

    throw new NetworkException(
      "Failed to detect repository visibility. Status: {$anonResponse->status()}. Response: {$anonResponse->body()}",
    );
  }

  private function showTokenHelp(string $owner, string $repo): void
  {
    PromptHelper::warning("RECOMMENDED: Use a classic token with 'repo' scope");
    PromptHelper::info("Classic: https://github.com/settings/tokens -> Generate (classic) -> Select 'repo'");
    PromptHelper::info(
      "Fine-grained: https://github.com/settings/personal-access-tokens -> Add {$owner}/{$repo} to 'Repository access' -> Enable Contents+Metadata permissions",
    );
  }

  private function executeFetch(string $owner, string $repo, bool $includePreReleases): array
  {
    $url = sprintf('%s/repos/%s/%s/releases', self::API_BASE, $owner, $repo);

    /** @var Response $response */
    $response = $this->authenticatedRequest($url);

    if ($response->status() === 404) {
      PromptHelper::info('No releases found. Attempting branch fallback...');
      return $this->handleBranchFallback($owner, $repo);
    }

    if (!$response->successful()) {
      throw new NetworkException(
        "Failed to fetch releases. Status: {$response->status()}. Response: {$response->body()}",
      );
    }

    $data = $response->json();

    if (empty($data) || !is_array($data)) {
      PromptHelper::info('No releases found. Using default branch as fallback.');
      return $this->handleBranchFallback($owner, $repo);
    }

    $filtered = array_values(
      array_filter($data, static function (mixed $release) use ($includePreReleases): bool {
        if (!is_array($release)) {
          return false;
        }
        if (($release['draft'] ?? false) === true) {
          return false;
        }
        if (!$includePreReleases && ($release['prerelease'] ?? false) === true) {
          return false;
        }
        return true;
      }),
    );

    if ($filtered === []) {
      PromptHelper::info('No suitable releases found. Using default branch as fallback.');
      return $this->handleBranchFallback($owner, $repo);
    }

    return $filtered;
  }

  private function handleBranchFallback(string $owner, string $repo): array
  {
    $repoUrl = sprintf('%s/repos/%s/%s', self::API_BASE, $owner, $repo);

    /** @var Response $response */
    $response = $this->authenticatedRequest($repoUrl);

    if (!$response->successful()) {
      throw new NetworkException("Unable to retrieve repository information. Status: {$response->status()}");
    }

    $repoData = $response->json();
    $branch = (string) ($repoData['default_branch'] ?? 'main');

    PromptHelper::info("Using default branch: {$branch}");

    return [
      [
        'tag_name' => $branch,
        'name' => "Default Branch: {$branch}",
        'zipball_url' => sprintf('%s/repos/%s/%s/zipball/%s', self::API_BASE, $owner, $repo, $branch),
        'published_at' => now()->toIso8601String(),
        'is_branch_fallback' => true,
      ],
    ];
  }

  private function authenticatedRequest(string $url): Response
  {
    $request = Http::withHeaders([
      'Accept' => 'application/vnd.github+json',
      'X-GitHub-Api-Version' => '2022-11-28',
      'User-Agent' => 'WebKernel-Installer',
    ])->timeout(self::CONNECT_TIMEOUT);

    if ($this->token && trim($this->token) !== '') {
      $request = $request->withToken(trim($this->token));
    }

    if ($this->insecure) {
      $request = $request->withoutVerifying();
    }

    /** @var Response $response */
    $response = $request->get($url);

    return $response;
  }

  private function askForTokenInteractively(string $owner, string $repo): void
  {
    PromptHelper::info('This repository is private and requires authentication.');

    $this->token = trim(
      PromptHelper::textHidden(
        label: 'Enter GitHub Token (classic or fine-grained)',
        placeholder: 'ghp_... or github_pat_...',
        required: true,
      ),
    );

    $this->tokenLoadedFromConfig = false;

    $scope = PromptHelper::select('Save token for future use?', [
      'repo' => "This repository only: {$owner}/{$repo}",
      'owner' => "All repositories from: {$owner}",
      'session' => 'Session only (not saved)',
    ]);

    if ($scope !== 'session') {
      $repoArg = $scope === 'repo' ? $repo : null;
      $this->config->saveGithubToken($owner, $this->token, $repoArg);
      PromptHelper::success('Token saved.');
    }
  }

  public function downloadRelease(array $release, string $targetDir): bool
  {
    $zipUrl = (string) ($release['zipball_url'] ?? '');

    if ($zipUrl === '') {
      throw new NetworkException('Release has no zipball_url.');
    }

    PromptHelper::info('Starting download...');

    $zipContent = $this->downloadZipball($zipUrl);

    return $this->extractArchive($zipContent, $targetDir, $release);
  }

  private function downloadZipball(string $url): string
  {
    /**current url */
    $currentUrl = $url;
    $attempt = 0;

    while ($attempt++ < self::MAX_REDIRECTS) {
      $lastProgress = 0;

      $ch = curl_init($currentUrl);

      if ($ch === false) {
        throw new NetworkException('Failed to initialize cURL.');
      }

      $headers = ['User-Agent: WebKernel-Installer'];

      $host = (string) (parse_url($currentUrl, PHP_URL_HOST) ?: '');
      $isGitHub =
        str_contains($host, 'github.com') ||
        str_contains($host, 'githubusercontent.com') ||
        str_contains($host, 'codeload.github.com');

      if ($this->token && $isGitHub) {
        $headers[] = 'Authorization: Bearer ' . trim($this->token);
        $headers[] = 'Accept: application/vnd.github+json';
        $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
      }

      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => self::DOWNLOAD_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => static function (
          mixed $ch,
          float|int $downloadSize,
          float|int $downloaded,
          float|int $uploadSize,
          float|int $uploaded,
        ) use (&$lastProgress): int {
          if ($downloadSize > 0) {
            $percent = (int) (($downloaded / $downloadSize) * 100);

            foreach (self::CURL_PROGRESS_THRESHOLDS as $threshold) {
              if ($percent >= $threshold && $lastProgress < $threshold) {
                echo " {$threshold}%";
                $lastProgress = $threshold;
                break;
              }
            }
          }
          return 0;
        },
      ]);

      if ($this->insecure) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
      }

      $raw = curl_exec($ch);
      $error = curl_error($ch);
      $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
      curl_close($ch);

      if ($raw === false) {
        throw new NetworkException("Download failed: {$error}");
      }

      $headerText = substr($raw, 0, $headerSize);
      $body = substr($raw, $headerSize);

      if ($httpCode >= 300 && $httpCode < 400) {
        $location = $this->extractHeaderValue($headerText, 'Location');

        if (!$location) {
          throw new NetworkException('Redirect without Location header.');
        }

        if (!parse_url($location, PHP_URL_SCHEME)) {
          $location = $this->resolveRelativeUrl($currentUrl, $location);
        }

        $currentUrl = $location;
        continue;
      }

      if ($httpCode === 200) {
        echo "\n";
        PromptHelper::success('Downloaded ' . $this->formatBytes(strlen($body)));
        return $body;
      }

      throw new NetworkException("Download failed with HTTP {$httpCode}");
    }

    throw new NetworkException('Too many redirects.');
  }

  public function verifyChecksum(string $content, array $release): bool
  {
    $expectedChecksum = $release['checksum'] ?? ($release['sha256'] ?? null);

    if (empty($expectedChecksum)) {
      return true;
    }

    $actualChecksum = hash('sha256', $content);

    if (!hash_equals((string) $actualChecksum, (string) $expectedChecksum)) {
      throw new IntegrityException("Checksum mismatch. Expected: {$expectedChecksum}, Got: {$actualChecksum}");
    }

    return true;
  }

  private function parseIdentifier(string $identifier): array
  {
    if (preg_match('#github\.com/([^/]+)/([^/]+?)(?:\.git)?$#i', $identifier, $m)) {
      return [$m[1], $m[2]];
    }

    if (preg_match('#^([^/]+)/([^/]+)$#', $identifier, $m)) {
      return [$m[1], $m[2]];
    }

    throw new NetworkException('Invalid GitHub identifier: ' . $identifier);
  }

  private function extractArchive(string $zipContent, string $targetDir, array $release): bool
  {
    $this->verifyChecksum($zipContent, $release);

    $tempFile = tempnam(sys_get_temp_dir(), 'wk_module_');

    if ($tempFile === false) {
      throw new NetworkException('Failed to create temp file.');
    }

    if (file_put_contents($tempFile, $zipContent) === false) {
      @unlink($tempFile);
      throw new NetworkException('Failed to write temp file.');
    }

    try {
      File::ensureDirectoryExists($targetDir);

      $zip = new ZipArchive();
      $openResult = $zip->open($tempFile);

      if ($openResult !== true) {
        throw new NetworkException("Failed to open ZIP. Error: {$openResult}");
      }

      if (!$zip->extractTo($targetDir)) {
        $zip->close();
        throw new NetworkException('Failed to extract ZIP.');
      }

      $zip->close();

      $this->flattenGitHubArchive($targetDir);

      PromptHelper::success("Extracted to {$targetDir}");

      return true;
    } finally {
      if (file_exists($tempFile)) {
        @unlink($tempFile);
      }
    }
  }

  private function flattenGitHubArchive(string $targetDir): void
  {
    $dirs = File::directories($targetDir);

    if (count($dirs) !== 1) {
      return;
    }

    $sourceDir = (string) $dirs[0];
    $files = File::allFiles($sourceDir);

    foreach ($files as $file) {
      $relativePath = str_replace($sourceDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
      $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

      File::ensureDirectoryExists(dirname($targetPath));

      if (!File::move($file->getPathname(), $targetPath)) {
        throw new NetworkException("Failed to move: {$file->getPathname()}");
      }
    }

    File::deleteDirectory($sourceDir);
  }

  private function extractHeaderValue(string $headers, string $name): ?string
  {
    if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/im', $headers, $m)) {
      return trim($m[1]);
    }

    return null;
  }

  private function resolveRelativeUrl(string $base, string $relative): string
  {
    if (parse_url($relative, PHP_URL_SCHEME) !== null) {
      return $relative;
    }

    $baseParts = parse_url($base);
    $scheme = (string) ($baseParts['scheme'] ?? 'https');
    $host = (string) ($baseParts['host'] ?? '');
    $port = isset($baseParts['port']) ? ':' . (string) $baseParts['port'] : '';
    $basePath = (string) ($baseParts['path'] ?? '/');

    if (str_starts_with($relative, '/')) {
      return "{$scheme}://{$host}{$port}{$relative}";
    }

    $baseDir = (string) preg_replace('#/[^/]*$#', '/', $basePath);
    $full = "{$scheme}://{$host}{$port}{$baseDir}{$relative}";
    $norm = (string) preg_replace('#(/\.?/)#', '/', $full);

    while (strpos($norm, '/../') !== false) {
      $norm = (string) preg_replace('#/[^/]+/\.\./#', '/', $norm, 1);
    }

    return $norm;
  }

  private function formatBytes(int $bytes): string
  {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = (float) $bytes;

    while ($value >= 1024 && $i < count($units) - 1) {
      $value /= 1024;
      $i++;
    }

    return round($value, 2) . ' ' . $units[$i];
  }
}
