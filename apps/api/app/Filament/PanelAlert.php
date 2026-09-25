<?php

declare(strict_types=1);

namespace App\Filament;

use App\Inventory\InvalidInventory;
use App\Inventory\InventoryConflict;
use App\Pharmacy\InvalidOperatingHours;
use App\Pharmacy\PharmacyRuleException;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PanelAlert
{
    public static function notify(Throwable $exception): void
    {
        if (! self::expected($exception)) {
            self::logUnexpected($exception);

            throw $exception;
        }

        Notification::make()
            ->title($exception->getMessage() !== '' ? $exception->getMessage() : 'That change could not be saved.')
            ->danger()
            ->send();
    }

    public static function halt(Throwable $exception): never
    {
        if (! self::expected($exception)) {
            self::logUnexpected($exception);

            throw $exception;
        }

        self::notify($exception);

        throw new Halt;
    }

    private static function expected(Throwable $exception): bool
    {
        return $exception instanceof InventoryConflict
            || $exception instanceof InvalidInventory
            || $exception instanceof InvalidOperatingHours
            || $exception instanceof PharmacyRuleException;
    }

    private static function logUnexpected(Throwable $exception): void
    {
        Log::error('Unexpected panel failure.', [
            'exception' => $exception::class,
        ]);
    }
}
