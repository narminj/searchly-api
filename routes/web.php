<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DocsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

/*
|--------------------------------------------------------------------------
| Authentication (session / web guard)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Protected technical documentation (any authenticated user)
|--------------------------------------------------------------------------
| Lives under /manual, NOT /docs — l5-swagger owns /docs (its OpenAPI spec)
| and /api/documentation (Swagger UI).
*/
Route::middleware('auth')->group(function () {
    Route::get('/manual', [DocsController::class, 'index'])->name('manual');
    Route::get('/manual/offline', [DocsController::class, 'offline']);
    Route::get('/manual/pdf', [DocsController::class, 'pdf']);
});

/*
|--------------------------------------------------------------------------
| Admin-only
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin/users', [AdminController::class, 'users'])->name('admin.users');
});
