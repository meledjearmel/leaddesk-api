<?php

use Illuminate\Http\Request;
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
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/user', fn (Request $request) => $request->user())->name('user');
    });
});
