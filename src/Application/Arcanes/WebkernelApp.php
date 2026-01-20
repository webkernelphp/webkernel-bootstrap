<?php declare(strict_types=1);

namespace Webkernel\Arcanes;

use Illuminate\Support\ServiceProvider;

/**
 * Base class for WebKernel modules
 *
 * Modules extend this class and implement configureModule()
 * to declare their configuration using a fluent builder.
 */
abstract class WebkernelApp extends ServiceProvider
{
  protected string $basePath;
  protected string $modulePath;
  private ?ModuleConfig $config = null;

  public function __construct($app = null, string $basePath = '', string $modulePath = '')
  {
    if ($app !== null) {
      parent::__construct($app);
    }

    $this->basePath = $basePath;
    $this->modulePath = $modulePath;
  }

  /**
   * Configure the module
   *
   * Must return the result of ->build() on the ModuleBuilder
   *
   * Example:
   * return $this->module()
   *     ->id('vendor.module')
   *     ->name('Module Name')
   *     ->version('1.0.0')
   *     ->installPath(
   *         in: 'app-platform/Vendor/Module',
   *         for: 'App\\Module\\Vendor\\Module'
   *     )
   *     ->build();
   */
  abstract public function configureModule(): ModuleConfig;

  /**
   * Get module builder instance
   */
  protected function module(): ModuleBuilder
  {
    return new ModuleBuilder();
  }

  /**
   * Get module configuration
   */
  public function getModuleConfig(): ModuleConfig
  {
    if ($this->config === null) {
      $this->config = $this->configureModule();
    }

    return $this->config;
  }

  /**
   * Resolve relative path to absolute module path
   */
  protected function modulePath(string $relativePath = ''): string
  {
    if ($relativePath === '') {
      return $this->modulePath;
    }

    return $this->modulePath . '/' . ltrim($relativePath, '/');
  }

  /**
   * Get bridge path for foreign function interface
   */
  protected function bridgePath(string $relativePath = ''): string
  {
    $config = $this->getModuleConfig();
    $bridgeBase = $config->bridgePath;

    return $this->modulePath($bridgeBase . ($relativePath !== '' ? '/' . ltrim($relativePath, '/') : ''));
  }

  /**
   * Load module configuration file
   */
  protected function loadConfig(string $key): array
  {
    $configFile = $this->modulePath("config/{$key}.php");

    if (!file_exists($configFile)) {
      return [];
    }

    return require $configFile;
  }

  /**
   * Publish module configuration files
   */
  protected function publishConfig(): void
  {
    $config = $this->getModuleConfig();

    if (empty($config->publishableConfigFiles)) {
      return;
    }

    foreach ($config->publishableConfigFiles as $tag => $files) {
      $publishes = [];

      foreach ($files as $file) {
        $source = $this->modulePath($file);
        $destination = config_path(basename($file));

        if (file_exists($source)) {
          $publishes[$source] = $destination;
        }
      }

      if (!empty($publishes)) {
        $this->publishes($publishes, $tag);
      }
    }
  }

  /**
   * Register module views
   */
  protected function registerViews(): void
  {
    $config = $this->getModuleConfig();

    foreach ($config->viewsPaths as $viewsPath) {
      $fullPath = $this->modulePath($viewsPath);

      if (is_dir($fullPath)) {
        foreach ($config->viewNamespaces as $namespace) {
          $this->loadViewsFrom($fullPath, $namespace);
        }
      }
    }
  }

  /**
   * Register module translations
   */
  protected function registerTranslations(): void
  {
    $config = $this->getModuleConfig();

    foreach ($config->langPaths as $langPath) {
      $fullPath = $this->modulePath($langPath);

      if (is_dir($fullPath)) {
        $this->loadTranslationsFrom($fullPath, $config->id);
      }
    }
  }

  /**
   * Register module migrations
   */
  protected function registerMigrations(): void
  {
    $config = $this->getModuleConfig();

    foreach ($config->migrationsPaths as $migrationsPath) {
      $fullPath = $this->modulePath($migrationsPath);

      if (is_dir($fullPath)) {
        $this->loadMigrationsFrom($fullPath);
      }
    }
  }

  /**
   * Register module routes
   */
  protected function registerRoutes(): void
  {
    $config = $this->getModuleConfig();

    foreach ($config->routesPaths as $routesPath) {
      $fullPath = $this->modulePath($routesPath);

      if (is_dir($fullPath)) {
        $this->loadRoutesFrom($fullPath);
      }
    }
  }
}
