<?php declare(strict_types=1);

namespace Webkernel\Arcanes;

/**
 * Unified module configuration definition
 *
 * This is the single source of truth for module configuration,
 * used by both runtime (BuildManifest) and installation (module manager).
 */
final class ModuleConfig
{
  public function __construct(
    public readonly string $id,
    public readonly string $name,
    public readonly string $version,
    public readonly string $description,
    public readonly string $phpVersion = '8.4',
    public readonly string $webkernelVersionConstraint = '>=1.0.0',
    public readonly array $dependencies = [],
    public readonly array $aliases = [],
    public readonly array $providers = [],
    public readonly array $providedComponents = [],
    public readonly array $viewNamespaces = [],
    public readonly array $viewsPaths = [],
    public readonly array $langPaths = [],
    public readonly array $routesPaths = [],
    public readonly array $migrationsPaths = [],
    public readonly array $seedersPaths = [],
    public readonly array $factoriesPaths = [],
    public readonly array $testsPaths = [],
    public readonly array $fixturesPaths = [],
    public readonly array $benchmarksPaths = [],
    public readonly array $helpersPaths = [],
    public readonly array $consolePaths = [],
    public readonly array $commandsPaths = [],
    public readonly array $policiesPaths = [],
    public readonly array $eventsPaths = [],
    public readonly array $listenersPaths = [],
    public readonly array $jobsPaths = [],
    public readonly array $notificationsPaths = [],
    public readonly array $exceptionsPaths = [],
    public readonly array $middlewaresPaths = [],
    public readonly array $dtoPaths = [],
    public readonly array $contractsPaths = [],
    public readonly array $repositoriesPaths = [],
    public readonly array $servicesPaths = [],
    public readonly array $modelsPaths = [],
    public readonly array $controllersPaths = [],
    public readonly array $componentsPaths = [],
    public readonly array $resourcesPaths = [],
    public readonly array $configPaths = [],
    public readonly array $publishableConfigFiles = [],
    public readonly array $assetsPaths = [],
    public readonly array $publicPaths = [],
    public readonly array $docsPaths = [],
    public readonly array $examplesPaths = [],
    public readonly string $bridgePath = 'bridge',
    public readonly string $installPath = '',
    public readonly string $namespace = '',
    public readonly array $supportElements = [],
    public readonly array $extra = [],
  ) {}
}
