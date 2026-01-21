<?php declare(strict_types=1);
namespace Webkernel\Presentation;

use Filament\Support\Enums\Width;
use Filament\Enums\GlobalSearchPosition;
use Filament\Support\Enums\Platform;
use Webkernel\Aptitudes\Users\Filament\Auth\RegisterOwner;
use Webkernel\Aptitudes\Base\Filament\Pages\WelcomeSystemDashboard;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Webkernel\Panels\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class SystemPanelProvider extends PanelProvider
{
  public function panel(Panel $panel): Panel
  {
    return $panel
      ->id('system')
      ->path('system')
      ->colors([
        'primary' => Color::Default,
      ])
      ->navigationGroups(['Administration', 'Platform Tools', 'System Management'])
      ->brandLogo(
        'https://raw.githubusercontent.com/numerimondes/.github/refs/heads/main/assets/brands/numerimondes/identity/logos/v2/logo_entier_v3.png',
      )
      ->darkModeBrandLogo(
        'https://raw.githubusercontent.com/numerimondes/.github/refs/heads/main/assets/brands/numerimondes/identity/logos/v2/numerimondes-white.png',
      )
      ->brandLogoHeight('2.5rem')
      ->brandName('Numerimondes')
      ->sidebarCollapsibleOnDesktop()
      ->maxContentWidth(Width::ScreenTwoExtraLarge)
      ->strictAuthorization(false)
      ->topbar(false)
      ->globalSearch(position: GlobalSearchPosition::Sidebar)
      ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
      ->globalSearchFieldSuffix(
        fn(): ?string => match (Platform::detect()) {
          Platform::Windows, Platform::Linux => 'CTRL+K',
          Platform::Mac => '⌘K',
          default => null,
        },
      )
      ->spa()
      ->login()
      ->passwordReset()
      ->emailVerification()
      ->emailChangeVerification()
      ->loginRouteSlug('login')
      ->registrationRouteSlug('register')
      ->databaseNotifications()
      ->passwordResetRoutePrefix('password-reset')
      ->passwordResetRequestRouteSlug('request')
      ->passwordResetRouteSlug('reset')
      ->emailVerificationRoutePrefix('email-verification')
      ->emailVerificationPromptRouteSlug('prompt')
      ->emailVerificationRouteSlug('verify')
      ->emailChangeVerificationRoutePrefix('email-change-verification')
      ->emailChangeVerificationRouteSlug('verify')

      ->discoverResources(
        in: webkernel_path('Presentation/System/Resources'),
        for: 'Webkernel\Presentation\System\Resources',
      )
      ->discoverPages(in: webkernel_path('Presentation/System/Pages'), for: 'Webkernel\Presentation\System\Pages')
      ->discoverWidgets(in: webkernel_path('Presentation/System/Widgets'), for: 'Webkernel\Presentation\System\Widgets')
      ->pages([Dashboard::class])
      ->widgets([AccountWidget::class, FilamentInfoWidget::class])
      ->middleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        AuthenticateSession::class,
        ShareErrorsFromSession::class,
        VerifyCsrfToken::class,
        SubstituteBindings::class,
        DisableBladeIconComponents::class,
        DispatchServingFilamentEvent::class,
      ])
      ->authMiddleware([Authenticate::class]);
  }
}
