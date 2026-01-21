<?php declare(strict_types=1);
$basePath = dirname(__DIR__, 2);

function basePath(string $subPath = ''): string
{
  $base = dirname(__DIR__, 2);
  return $subPath !== '' ? $base . DIRECTORY_SEPARATOR . ltrim($subPath, DIRECTORY_SEPARATOR) : $base;
}

/**
 * Resolve a path relative to Webkernel namespace root.
 */
function webkernel_path(string $subPath = ''): string
{
  $base = basePath('bootstrap/src/Application');
  return $subPath !== '' ? $base . DIRECTORY_SEPARATOR . ltrim($subPath, DIRECTORY_SEPARATOR) : $base;
}

/**
 * Resolve a path one level above Webkernel namespace root.
 */
function webkernel_upperpath(string $subPath = ''): string
{
  $base = basePath('bootstrap/src');
  return $subPath !== '' ? $base . DIRECTORY_SEPARATOR . ltrim($subPath, DIRECTORY_SEPARATOR) : $base;
}
