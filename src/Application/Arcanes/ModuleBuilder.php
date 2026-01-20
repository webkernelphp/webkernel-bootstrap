<?php declare(strict_types=1);

namespace Webkernel\Arcanes;

/**
 * Fluent module configuration builder
 * Supports all paths and configuration options
 */
final class ModuleBuilder
{
  private string $id = '';
  private string $name = '';
  private string $version = '1.0.0';
  private string $description = '';
  private string $phpVersion = '8.4';
  private string $webkernelVersionConstraint = '>=1.0.0';
  private array $dependencies = [];
  private array $aliases = [];
  private array $providers = [];
  private array $providedComponents = [];
  private array $viewNamespaces = [];
  private array $viewsPaths = [];
  private array $langPaths = [];
  private array $routesPaths = [];
  private array $migrationsPaths = [];
  private array $seedersPaths = [];
  private array $factoriesPaths = [];
  private array $testsPaths = [];
  private array $fixturesPaths = [];
  private array $benchmarksPaths = [];
  private array $helpersPaths = [];
  private array $consolePaths = [];
  private array $commandsPaths = [];
  private array $policiesPaths = [];
  private array $eventsPaths = [];
  private array $listenersPaths = [];
  private array $jobsPaths = [];
  private array $notificationsPaths = [];
  private array $exceptionsPaths = [];
  private array $middlewaresPaths = [];
  private array $dtoPaths = [];
  private array $contractsPaths = [];
  private array $repositoriesPaths = [];
  private array $servicesPaths = [];
  private array $modelsPaths = [];
  private array $controllersPaths = [];
  private array $componentsPaths = [];
  private array $resourcesPaths = [];
  private array $configPaths = [];
  private array $publishableConfigFiles = [];
  private array $assetsPaths = [];
  private array $publicPaths = [];
  private array $docsPaths = [];
  private array $examplesPaths = [];
  private string $bridgePath = 'bridge';
  private string $installPath = '';
  private string $namespace = '';
  private array $supportElements = [];
  private array $extra = [];

  public function id(string $id): self
  {
    $this->id = $id;
    return $this;
  }

  public function name(string $name): self
  {
    $this->name = $name;
    return $this;
  }

  public function version(string $version): self
  {
    $this->version = $version;
    return $this;
  }

  public function description(string $description): self
  {
    $this->description = $description;
    return $this;
  }

  public function phpVersion(string $phpVersion): self
  {
    $this->phpVersion = $phpVersion;
    return $this;
  }

  public function webkernelVersionConstraint(string $constraint): self
  {
    $this->webkernelVersionConstraint = $constraint;
    return $this;
  }

  public function dependencies(array $deps): self
  {
    $this->dependencies = $deps;
    return $this;
  }

  public function aliases(array $aliases): self
  {
    $this->aliases = $aliases;
    return $this;
  }

  public function providers(array $providers): self
  {
    $this->providers = $providers;
    return $this;
  }

  public function moduleProvides(array $components): self
  {
    $this->providedComponents = $components;
    return $this;
  }

  public function viewNamespaces(array $namespaces): self
  {
    $this->viewNamespaces = $namespaces;
    return $this;
  }

  public function viewsPaths(array $paths): self
  {
    $this->viewsPaths = $paths;
    return $this;
  }

  public function langPaths(array $paths): self
  {
    $this->langPaths = $paths;
    return $this;
  }

  public function routesPaths(array $paths): self
  {
    $this->routesPaths = $paths;
    return $this;
  }

  public function migrationsPaths(array $paths): self
  {
    $this->migrationsPaths = $paths;
    return $this;
  }

  public function seedersPaths(array $paths): self
  {
    $this->seedersPaths = $paths;
    return $this;
  }

  public function factoriesPaths(array $paths): self
  {
    $this->factoriesPaths = $paths;
    return $this;
  }

  public function testsPaths(array $paths): self
  {
    $this->testsPaths = $paths;
    return $this;
  }

  public function fixturesPaths(array $paths): self
  {
    $this->fixturesPaths = $paths;
    return $this;
  }

  public function benchmarksPaths(array $paths): self
  {
    $this->benchmarksPaths = $paths;
    return $this;
  }

  public function helpersPaths(array $paths): self
  {
    $this->helpersPaths = $paths;
    return $this;
  }

  public function consolePaths(array $paths): self
  {
    $this->consolePaths = $paths;
    return $this;
  }

  public function commandsPaths(array $paths): self
  {
    $this->commandsPaths = $paths;
    return $this;
  }

  public function policiesPaths(array $paths): self
  {
    $this->policiesPaths = $paths;
    return $this;
  }

  public function eventsPaths(array $paths): self
  {
    $this->eventsPaths = $paths;
    return $this;
  }

  public function listenersPaths(array $paths): self
  {
    $this->listenersPaths = $paths;
    return $this;
  }

  public function jobsPaths(array $paths): self
  {
    $this->jobsPaths = $paths;
    return $this;
  }

  public function notificationsPaths(array $paths): self
  {
    $this->notificationsPaths = $paths;
    return $this;
  }

  public function exceptionsPaths(array $paths): self
  {
    $this->exceptionsPaths = $paths;
    return $this;
  }

  public function middlewaresPaths(array $paths): self
  {
    $this->middlewaresPaths = $paths;
    return $this;
  }

  public function dtoPaths(array $paths): self
  {
    $this->dtoPaths = $paths;
    return $this;
  }

  public function contractsPaths(array $paths): self
  {
    $this->contractsPaths = $paths;
    return $this;
  }

  public function repositoriesPaths(array $paths): self
  {
    $this->repositoriesPaths = $paths;
    return $this;
  }

  public function servicesPaths(array $paths): self
  {
    $this->servicesPaths = $paths;
    return $this;
  }

  public function modelsPaths(array $paths): self
  {
    $this->modelsPaths = $paths;
    return $this;
  }

  public function controllersPaths(array $paths): self
  {
    $this->controllersPaths = $paths;
    return $this;
  }

  public function componentsPaths(array $paths): self
  {
    $this->componentsPaths = $paths;
    return $this;
  }

  public function resourcesPaths(array $paths): self
  {
    $this->resourcesPaths = $paths;
    return $this;
  }

  public function configPaths(array $paths): self
  {
    $this->configPaths = $paths;
    return $this;
  }

  public function publishableConfigFiles(array $files): self
  {
    $this->publishableConfigFiles = $files;
    return $this;
  }

  public function assetsPaths(array $paths): self
  {
    $this->assetsPaths = $paths;
    return $this;
  }

  public function publicPaths(array $paths): self
  {
    $this->publicPaths = $paths;
    return $this;
  }

  public function docsPaths(array $paths): self
  {
    $this->docsPaths = $paths;
    return $this;
  }

  public function examplesPaths(array $paths): self
  {
    $this->examplesPaths = $paths;
    return $this;
  }

  public function bridgePath(string $path): self
  {
    $this->bridgePath = $path;
    return $this;
  }

  /**
   * Define where the module should be installed and its namespace
   *
   * @param string $in Path relative to project root (e.g., 'app-platform/Vendor/Module')
   * @param string $for Full namespace (e.g., 'App\\Module\\Vendor\\Module')
   */
  public function installPath(string $in, string $for): self
  {
    $this->installPath = $in;
    $this->namespace = $for;
    return $this;
  }

  public function supportElements(array $elements): self
  {
    $this->supportElements = $elements;
    return $this;
  }

  public function extra(array $extra): self
  {
    $this->extra = $extra;
    return $this;
  }

  public function build(): ModuleConfig
  {
    return new ModuleConfig(
      id: $this->id,
      name: $this->name,
      version: $this->version,
      description: $this->description,
      phpVersion: $this->phpVersion,
      webkernelVersionConstraint: $this->webkernelVersionConstraint,
      dependencies: $this->dependencies,
      aliases: $this->aliases,
      providers: $this->providers,
      providedComponents: $this->providedComponents,
      viewNamespaces: $this->viewNamespaces,
      viewsPaths: $this->viewsPaths,
      langPaths: $this->langPaths,
      routesPaths: $this->routesPaths,
      migrationsPaths: $this->migrationsPaths,
      seedersPaths: $this->seedersPaths,
      factoriesPaths: $this->factoriesPaths,
      testsPaths: $this->testsPaths,
      fixturesPaths: $this->fixturesPaths,
      benchmarksPaths: $this->benchmarksPaths,
      helpersPaths: $this->helpersPaths,
      consolePaths: $this->consolePaths,
      commandsPaths: $this->commandsPaths,
      policiesPaths: $this->policiesPaths,
      eventsPaths: $this->eventsPaths,
      listenersPaths: $this->listenersPaths,
      jobsPaths: $this->jobsPaths,
      notificationsPaths: $this->notificationsPaths,
      exceptionsPaths: $this->exceptionsPaths,
      middlewaresPaths: $this->middlewaresPaths,
      dtoPaths: $this->dtoPaths,
      contractsPaths: $this->contractsPaths,
      repositoriesPaths: $this->repositoriesPaths,
      servicesPaths: $this->servicesPaths,
      modelsPaths: $this->modelsPaths,
      controllersPaths: $this->controllersPaths,
      componentsPaths: $this->componentsPaths,
      resourcesPaths: $this->resourcesPaths,
      configPaths: $this->configPaths,
      publishableConfigFiles: $this->publishableConfigFiles,
      assetsPaths: $this->assetsPaths,
      publicPaths: $this->publicPaths,
      docsPaths: $this->docsPaths,
      examplesPaths: $this->examplesPaths,
      bridgePath: $this->bridgePath,
      installPath: $this->installPath,
      namespace: $this->namespace,
      supportElements: $this->supportElements,
      extra: $this->extra,
    );
  }
}
