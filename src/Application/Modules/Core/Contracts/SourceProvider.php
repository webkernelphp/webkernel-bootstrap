<?php declare(strict_types=1);

namespace Webkernel\Modules\Core\Contracts;

interface SourceProvider
{
  public function supports(string $identifier): bool;

  public function fetchReleases(string $identifier, bool $includePreReleases): ?array;

  public function downloadRelease(array $release, string $targetDir): bool;

  public function verifyChecksum(string $content, array $release): bool;
}
