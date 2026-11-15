<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API JSON versionnée, consommée par l'application de bureau (leaddesk-app)
|--------------------------------------------------------------------------
|
| Contrat : docs/api/ (source de vérité). Toute évolution d'une route met à
| jour le contrat dans le même changement.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('/auth/token', [AuthController::class, 'token'])->name('auth.token');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('/auth/token', [AuthController::class, 'destroy'])->name('auth.token.destroy');
        Route::get('/user', [AuthController::class, 'show'])->name('user');

        Route::get('/company', [CompanyController::class, 'show'])->name('company.show');
        Route::post('/company', [CompanyController::class, 'store'])->name('company.store');
        Route::patch('/company', [CompanyController::class, 'update'])->name('company.update');
    });
});
