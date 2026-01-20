<?php declare(strict_types=1);

namespace Webkernel\Arcanes;

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

/**
 * Unified module metadata for both runtime and installation
 */
final class ModuleMetadata
{
  public function __construct(
    public readonly string $id,
    public readonly string $name,
    public readonly string $version,
    public readonly string $description,
    public readonly string $installPath,
    public readonly string $namespace,
    public readonly string $phpVersion = '8.4',
    public readonly string $webkernelVersionConstraint = '>=1.0.0',
    public readonly array $dependencies = [],
    public readonly array $aliases = [],
    public readonly array $providers = [],
    public readonly array $providedComponents = [],
    public readonly array $supportElements = [],
    public readonly array $extra = [],
  ) {}

  /**
   * Extract metadata from module class file using php-parser
   */
  public static function fromModuleFile(string $filePath): ?self
  {
    if (!file_exists($filePath)) {
      return null;
    }

    $code = file_get_contents($filePath);
    $parser = new ParserFactory()->createForNewestSupportedVersion();

    try {
      $ast = $parser->parse($code);
    } catch (\Throwable) {
      return null;
    }

    $visitor = new class extends NodeVisitorAbstract {
      public ?string $namespace = null;
      public ?string $className = null;
      public array $configData = [];

      public function enterNode(Node $node): void
      {
        if ($node instanceof Node\Stmt\Namespace_) {
          $this->namespace = $node->name?->toString();
        }

        if ($node instanceof Node\Stmt\Class_) {
          $this->className = $node->name?->toString();
        }

        if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'configureModule') {
          $this->extractConfigData($node);
        }
      }

      private function extractConfigData(Node\Stmt\ClassMethod $method): void
      {
        foreach ($method->stmts ?? [] as $stmt) {
          if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node\Expr\MethodCall) {
            $this->parseBuilderChain($stmt->expr);
          }
        }
      }

      private function parseBuilderChain(Node\Expr $expr): void
      {
        if ($expr instanceof Node\Expr\MethodCall) {
          $this->parseBuilderChain($expr->var);

          $methodName = $expr->name->toString();
          $args = $expr->args;

          if ($methodName === 'installPath' && count($args) >= 2) {
            foreach ($args as $arg) {
              if ($arg->name?->toString() === 'in' && $arg->value instanceof Node\Scalar\String_) {
                $this->configData['installPath'] = $arg->value->value;
              }
              if ($arg->name?->toString() === 'for' && $arg->value instanceof Node\Scalar\String_) {
                $this->configData['namespace'] = $arg->value->value;
              }
            }
          } elseif (count($args) > 0 && $args[0]->value instanceof Node\Scalar\String_) {
            $this->configData[$methodName] = $args[0]->value->value;
          } elseif (
            $methodName === 'supportElements' &&
            count($args) > 0 &&
            $args[0]->value instanceof Node\Expr\Array_
          ) {
            $this->configData['supportElements'] = $this->parseArray($args[0]->value);
          }
        }
      }

      private function parseArray(Node\Expr\Array_ $array): array
      {
        $result = [];
        foreach ($array->items as $item) {
          if ($item === null) {
            continue;
          }

          $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : null;
          $value = $item->value instanceof Node\Scalar\String_ ? $item->value->value : null;

          if ($key !== null && $value !== null) {
            $result[$key] = $value;
          }
        }
        return $result;
      }
    };

    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    if ($visitor->namespace === null || $visitor->className === null) {
      return null;
    }

    return new self(
      id: $visitor->configData['id'] ?? '',
      name: $visitor->configData['name'] ?? '',
      version: $visitor->configData['version'] ?? '1.0.0',
      description: $visitor->configData['description'] ?? '',
      installPath: $visitor->configData['installPath'] ?? '',
      namespace: $visitor->configData['namespace'] ?? $visitor->namespace,
      supportElements: $visitor->configData['supportElements'] ?? [],
    );
  }
}
