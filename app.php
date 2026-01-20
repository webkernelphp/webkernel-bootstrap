<?php declare(strict_types=1);
namespace Bootstrap;
require_once __DIR__ . '/src/Application/Fastboot.php';
use Webkernel\Fastboot;
# FASTBOOT APP | WEBKERNEL_BOOTSTRAP_ENTRY | DO_NOT_EDIT
# THIS FILE IS AUTO-GENERATED | WILL BE OVERWRITTEN_ON_UPDATE
if (!class_exists(FastApplication::class, false)) {
  final class FastApplication extends Fastboot
  {
    public const string VERSION = '0.1.4';
    public const string YEAR = '2026';
  }
}
return FastApplication::boot();
