<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProductSearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Searchly
|--------------------------------------------------------------------------
|
| GET  /api/health              App + Elasticsearch cluster health probe
| GET  /api/products/search     Full search with filters, sorting, aggregations
| GET  /api/products/suggest    Autocomplete prefix suggestions
| GET  /api/products/{id}       Single product document by ID
| POST /api/products/{id}/click Click-through tracking (popularity++)
|
*/

Route::get('/health', HealthController::class)->middleware('throttle:api');

Route::prefix('products')->group(function () {
    Route::get('/search', [ProductSearchController::class, 'search'])->middleware('throttle:search');
    Route::get('/suggest', [ProductSearchController::class, 'suggest'])->middleware('throttle:suggest');
    Route::get('/{id}', [ProductSearchController::class, 'show'])->where('id', '[0-9]+')->middleware('throttle:api');
    Route::post('/{id}/click', [ProductSearchController::class, 'click'])->where('id', '[0-9]+')->middleware('throttle:api');
});
