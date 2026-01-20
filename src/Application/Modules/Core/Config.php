<?php declare(strict_types=1);

namespace Webkernel\Modules\Core;

final class Config
{
  public const string LOCK_DIR = 'bootstrap/cache/webkernel/locks';
  public const string BACKUP_DIR = 'bootstrap/cache/webkernel/backups';
  public const string CONFIG_FILE = '.webkernel/config.json';
  public const string MODULE_DIR = 'app-platform';
  public const string BOOTSTRAP_DIR = 'bootstrap';
  public const string COMPOSER_JSON = 'composer.json';
  public const int LOCK_TIMEOUT = 300;
  public const int BACKUP_KEEP_COUNT = 5;
  public const int HOOK_TIMEOUT = 60;
}
