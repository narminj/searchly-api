<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name'    => 'Laravel Elasticsearch Demo',
        'version' => '1.0.0',
        'api'     => '/api/products/search',
        'docs'    => 'See README.md for full API documentation',
    ]);
});
