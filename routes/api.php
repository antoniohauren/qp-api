<?php

use App\Http\Controllers\SearchController;
use App\Http\Controllers\SeatController;
use App\Http\Controllers\StopController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:quero-passagem')->group(function (): void {
    Route::get('/stops', StopController::class)->name('stops');
    Route::get('/search/{from}/{to}/{travelDate}', SearchController::class)->name('search');
    Route::post('/seats', SeatController::class)->name('seats');
});
