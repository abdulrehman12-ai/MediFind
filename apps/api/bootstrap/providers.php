<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PharmacyPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    PharmacyPanelProvider::class,
];
