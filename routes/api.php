<?php

use App\Http\Controllers\StopController;
use Illuminate\Support\Facades\Route;

Route::get('/stops', StopController::class)->name('stops');
Route::get('/stops/{stop_id}', [StopController::class, 'show'])->name('stops.show');
