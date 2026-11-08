<?php

declare(strict_types=1);

use CodingAgency\SpamStrike\Http\Controllers\NoscriptNoticeController;
use CodingAgency\SpamStrike\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

Route::prefix((string) config('spam-strike.route.prefix'))
    ->middleware((array) config('spam-strike.route.middleware'))
    ->group(function (): void {
        Route::get('token', TokenController::class)
            ->middleware('throttle:'.config('spam-strike.route.throttle'))
            ->name('spam-strike.token');

        Route::get('noscript', NoscriptNoticeController::class)->name('spam-strike.noscript');
    });
