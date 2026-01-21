<?php declare(strict_types=1);

# PROVIDERS CONFIG | WEBKERNEL_BOOTSTRAP_ENTRY | DO_NOT_EDIT
# THIS FILE IS AUTO-GENERATED | WILL BE OVERWRITTEN_ON_UPDATE

// Path helper
include_once __DIR__ . '/helpers.php';
include_once __DIR__ . '/paths.php';

return [
  /*
    |--------------------------------------------------------------------------
    | Core Providers
    |--------------------------------------------------------------------------
    */
  \Webkernel\Panels\ThemeServiceProvider::class,
  \Webkernel\AppPanelProvider::class,
  \Webkernel\CliServiceProvider::class,
  \Webkernel\Presentation\SystemPanelProvider::class,

  /*
    |--------------------------------------------------------------------------
    | Bridge Providers (optional)
    |--------------------------------------------------------------------------
    */
  // \Webkernel\Bridges\SystemPanelProvider::class,
  // \Webkernel\Bridges\BridgeServiceProvider::class,
];
