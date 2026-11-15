<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Prototypes\Realtime\RealtimeController;

/*
| Prototype 3 (phase 0). Chargé par bootstrap/app.php seulement quand
| PROTOTYPES_ENABLED vaut true. Rien ici ne fait partie de l'API /api/v1.
*/

Route::prefix('api/prototypes/realtime')->middleware('api')->group(function (): void {
    Route::post('/token', [RealtimeController::class, 'token'])->middleware('throttle:10,1');
    Route::post('/ping', [RealtimeController::class, 'ping'])->middleware('auth:sanctum');
});

// Autorisation des canaux privés par jeton Sanctum : POST /api/broadcasting/auth.
Broadcast::routes(['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']]);

Broadcast::channel(
    'prototypes.user.{id}',
    fn (User $user, int|string $id): bool => $user->id === (int) $id,
);
