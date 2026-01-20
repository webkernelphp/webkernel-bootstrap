<?php declare(strict_types=1);
namespace Bootstrap;
require_once __DIR__ . '/src/Application/Fastboot.php';
use Webkernel\Fastboot;
# FASTBOOT APP | WEBKERNEL_BOOTSTRAP_ENTRY | DO_NOT_EDIT
# THIS FILE IS AUTO-GENERATED | WILL BE OVERWRITTEN_ON_UPDATE
final class FastApplication extends Fastboot
{
  public const string SERIAL = '1.0.0-2025-a3f7b9c2';
}
return FastApplication::boot();
