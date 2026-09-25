<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pharmacy\Pages\EditPharmacyProfile;
use App\Http\Middleware\EnsurePanelSessionGeneration;
use App\Models\Pharmacy;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

final class PharmacyPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('pharmacy')
            ->path('pharmacy')
            ->login()
            ->profile()
            ->brandName('MediFind Pharmacy')
            ->colors(['primary' => Color::Teal])
            ->tenant(Pharmacy::class, ownershipRelationship: 'pharmacy')
            ->tenantProfile(EditPharmacyProfile::class)
            ->discoverResources(in: app_path('Filament/Pharmacy/Resources'), for: 'App\\Filament\\Pharmacy\\Resources')
            ->discoverPages(in: app_path('Filament/Pharmacy/Pages'), for: 'App\\Filament\\Pharmacy\\Pages')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePanelSessionGeneration::class,
            ])
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable()->brandName('MediFind'),
            ], isRequired: true);
    }
}
