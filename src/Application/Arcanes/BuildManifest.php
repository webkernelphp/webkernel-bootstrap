<?php declare(strict_types=1);

namespace Webkernel\Arcanes;

use Illuminate\Support\Facades\File;

/**
 * Manifest builder for static module discovery
 *
 * Uses the unified ModuleMetadata to extract configuration
 */
final class BuildManifest
{
  private string $basePath;
  private string $platformPath;

  public function __construct(string $basePath)
  {
    $this->basePath = $basePath;
    $this->platformPath = $basePath . '/app-platform';
  }

  public function build(string $outputPath): void
  {
    $manifest = $this->discover();

    File::ensureDirectoryExists(dirname($outputPath));

    $content = "<?php declare(strict_types=1);\nreturn " . $this->exportArray($manifest) . ";\n";
    File::put($outputPath, $content);
  }

  private function exportArray(mixed $value, int $indent = 0): string
  {
    if (is_array($value)) {
      if ($value === []) {
        return '[]';
      }

      $pad = str_repeat('    ', $indent);
      $padNext = str_repeat('    ', $indent + 1);
      $isAssoc = array_keys($value) !== range(0, count($value) - 1);

      $items = [];
      foreach ($value as $key => $val) {
        $exportedVal = $this->exportArray($val, $indent + 1);
        if ($isAssoc) {
          $items[] = $padNext . var_export($key, true) . ' => ' . $exportedVal;
        } else {
          $items[] = $padNext . $exportedVal;
        }
      }

      return "[\n" . implode(",\n", $items) . "\n" . $pad . ']';
    }

    return var_export($value, true);
  }

  private function discover(): array
  {
    $namespaces = [];
    $providers = ['critical' => [], 'deferred' => []];
    $middleware = ['global' => [], 'web' => [], 'api' => [], 'aliases' => []];
    $routes = ['web' => null, 'api' => null];
    $moduleRoutes = [];
    $modules = [];

    if (!is_dir($this->platformPath)) {
      return compact('namespaces', 'providers', 'middleware', 'routes', 'moduleRoutes', 'modules');
    }

    $vendorDirs = File::directories($this->platformPath);

    foreach ($vendorDirs as $vendorPath) {
      $vendor = basename($vendorPath);
      $modulePaths = File::directories($vendorPath);

      foreach ($modulePaths as $modulePath) {
        $moduleId = basename($modulePath);
        $moduleFile = $this->findModuleFile($modulePath);

        if ($moduleFile === null) {
          continue;
        }

        $metadata = ModuleMetadata::fromModuleFile($moduleFile);

        if ($metadata === null) {
          continue;
        }

        require_once $moduleFile;

        $content = File::get($moduleFile);
        if (!preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
          continue;
        }

        if (!preg_match('/class\s+(\w+Module)\s+extends\s+WebkernelApp/', $content, $classMatch)) {
          continue;
        }

        $fullClassName = trim($nsMatch[1]) . '\\' . trim($classMatch[1]);

        if (!class_exists($fullClassName) || !is_subclass_of($fullClassName, WebkernelApp::class)) {
          continue;
        }

        $instance = new $fullClassName($this->basePath, $modulePath);
        $config = $instance->getModuleConfig();

        if ($config === null) {
          continue;
        }

        $modules[$config->id] = [
          'vendor' => $vendor,
          'id' => $config->id,
          'name' => $config->name,
          'version' => $config->version,
          'path' => $modulePath,
          'class' => $fullClassName,
          'namespace' => $metadata->namespace,
          'install_path' => $metadata->installPath,
        ];

        $namespaces[$metadata->namespace] = $modulePath;
        $providers['critical'] = [...$providers['critical'], ...$config->providers];

        foreach ($config->routesPaths as $routesPath) {
          if ($routesPath !== '' && is_dir($modulePath . '/' . $routesPath)) {
            $moduleRoutes[] = $modulePath . '/' . $routesPath;
          }
        }
      }
    }

    return compact('namespaces', 'providers', 'middleware', 'routes', 'moduleRoutes', 'modules');
  }

  private function findModuleFile(string $modulePath): ?string
  {
    $files = File::glob($modulePath . '/*Module.php');

    foreach ($files as $file) {
      if (is_file($file)) {
        return $file;
      }
    }

    return null;
  }

  public static function execute(): void
  {
    $basePath = dirname(__DIR__, 3);
    $outputPath = $basePath . '/bootstrap/cache/webkernel-modules.php';

    $builder = new self($basePath);
    $builder->build($outputPath);

    echo "Module manifest built successfully at: {$outputPath}\n";
  }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
  BuildManifest::execute();
}
