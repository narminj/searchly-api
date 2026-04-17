<?php

use App\Http\Controllers\Api\ProductSearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Product Search
|--------------------------------------------------------------------------
|
| GET /api/products/search   Full search with filters, sorting, aggregations
| GET /api/products/suggest  Autocomplete prefix suggestions
| GET /api/products/{id}     Single product document by ID
|
*/

Route::prefix('products')->group(function () {
    Route::get('/search', [ProductSearchController::class, 'search']);
    Route::get('/suggest', [ProductSearchController::class, 'suggest']);
    Route::get('/{id}', [ProductSearchController::class, 'show'])->where('id', '[0-9]+');
});
