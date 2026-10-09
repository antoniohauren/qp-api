<?php

use App\Http\Controllers\StopController;
use Illuminate\Support\Facades\Route;

Route::get('/stops', StopController::class)->name('stops');
