<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name'    => 'Laravel Elasticsearch API',
        'version' => '1.0.0',
        'api'     => '/api/products/search',
    ]);
});
