<?php

use Illuminate\Support\Facades\Route;

// All non-API routes serve the React SPA
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
