<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health/live', [HealthController::class, 'live']);
    Route::get('/health/ready', [HealthController::class, 'ready']);

    Route::prefix('auth')->group(function (): void {
        Route::post('/otp/request', [OtpController::class, 'request'])->middleware('public.otp');
        Route::post('/otp/verify', [OtpController::class, 'verify'])->middleware('public.otp');
        Route::post('/logout', [SessionController::class, 'logout'])->middleware(['auth:sanctum', 'customer.access']);
        Route::post('/logout-all', [SessionController::class, 'logoutAll'])->middleware(['auth:sanctum', 'customer.access']);
    });

    Route::prefix('me')->middleware(['auth:sanctum', 'customer.access'])->group(function (): void {
        Route::get('/', [ProfileController::class, 'show']);
        Route::patch('/', [ProfileController::class, 'update']);
        Route::get('/addresses', [AddressController::class, 'index']);
        Route::post('/addresses', [AddressController::class, 'store']);
        Route::get('/addresses/{address}', [AddressController::class, 'show'])->whereUuid('address');
        Route::patch('/addresses/{address}', [AddressController::class, 'update'])->whereUuid('address');
        Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->whereUuid('address');
    });
});
