<?php

use App\Http\Controllers\Api\V1\ActivityTypeController;
use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AiProviderController;
use App\Http\Controllers\Api\V1\AuditEntryController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\WorkflowController;
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

        Route::apiResource('units', UnitController::class)->whereNumber('unit');
        Route::post('/units/{unit}/restore', [UnitController::class, 'restore'])->whereNumber('unit')->name('units.restore');

        Route::apiResource('positions', PositionController::class)->whereNumber('position');
        Route::post('/positions/{position}/restore', [PositionController::class, 'restore'])->whereNumber('position')->name('positions.restore');

        Route::apiResource('agents', AgentController::class)->whereNumber('agent');
        Route::controller(AgentController::class)->prefix('agents/{agent}')->whereNumber('agent')->name('agents.')->group(function (): void {
            Route::post('/restore', 'restore')->name('restore');
            Route::post('/position', 'changePosition')->name('position');
            Route::get('/assignments', 'assignments')->name('assignments');
            Route::put('/ai-models', 'syncAiModels')->name('ai-models');
            Route::post('/activate', 'activate')->name('activate');
            Route::post('/deactivate', 'deactivate')->name('deactivate');
        });

        Route::get('/ai-providers/catalog', [AiProviderController::class, 'catalog'])->name('ai-providers.catalog');
        Route::apiResource('ai-providers', AiProviderController::class)
            ->parameters(['ai-providers' => 'aiProvider'])
            ->whereNumber('aiProvider');
        Route::post('/ai-providers/{aiProvider}/models', [AiProviderController::class, 'storeModel'])
            ->whereNumber('aiProvider')->name('ai-providers.models.store');
        Route::patch('/ai-models/{aiModel}', [AiProviderController::class, 'updateModel'])
            ->whereNumber('aiModel')->name('ai-models.update');
        Route::delete('/ai-models/{aiModel}', [AiProviderController::class, 'destroyModel'])
            ->whereNumber('aiModel')->name('ai-models.destroy');

        Route::get('/audit-entries', [AuditEntryController::class, 'index'])->name('audit-entries.index');

        Route::apiResource('workflows', WorkflowController::class)->whereNumber('workflow');

        Route::apiResource('activity-types', ActivityTypeController::class)
            ->parameters(['activity-types' => 'activityType'])
            ->whereNumber('activityType');
    });
});
