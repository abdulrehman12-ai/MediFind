<?php

use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('throttle:10,1')->group(function (): void {
    Route::get('/invitations/accept', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/accept', [InvitationController::class, 'store'])->name('invitations.accept');
});
