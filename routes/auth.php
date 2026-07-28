<?php

use App\Http\Controllers\Auth\LogoutController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
 * There is deliberately no public registration route. Accounts exist only
 * because an administrator created or imported them.
 */

Route::middleware('guest')->group(function () {
    Volt::route('login', 'pages.auth.login')->name('login');

    Volt::route('forgot-password', 'pages.auth.forgot-password')->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('confirm-password', 'pages.auth.confirm-password')->name('password.confirm');

    Route::post('logout', LogoutController::class)->name('logout');
});
