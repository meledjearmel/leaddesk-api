<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\ActivityConversationController;
use App\Http\Controllers\Api\V1\ActivityTypeController;
use App\Http\Controllers\Api\V1\AgentContextController;
use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AiProviderController;
use App\Http\Controllers\Api\V1\AuditEntryController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanySettingsController;
use App\Http\Controllers\Api\V1\CostController;
use App\Http\Controllers\Api\V1\DecisionController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\ExecutionController;
use App\Http\Controllers\Api\V1\MissionController;
use App\Http\Controllers\Api\V1\OfficePlanController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\RuleController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskRunController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Http\Middleware\ResolveActingAgent;
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

    Route::middleware(['auth:sanctum', ResolveActingAgent::class])->group(function (): void {
        Route::delete('/auth/token', [AuthController::class, 'destroy'])->name('auth.token.destroy');
        Route::get('/user', [AuthController::class, 'show'])->name('user');

        Route::get('/company', [CompanyController::class, 'show'])->name('company.show');
        Route::post('/company', [CompanyController::class, 'store'])->name('company.store');
        Route::patch('/company', [CompanyController::class, 'update'])->name('company.update');
        Route::get('/missions', [MissionController::class, 'index'])->name('missions.index');
        Route::post('/missions', [MissionController::class, 'store'])->name('missions.store');
        Route::controller(MissionController::class)->prefix('missions/{mission}')->whereNumber('mission')->name('missions.')->group(function (): void {
            Route::get('/', 'show')->name('show');
            Route::post('/activity', 'link')->name('activity');
            Route::post('/complete', 'complete')->name('complete');
            Route::post('/cancel', 'cancel')->name('cancel');
        });
        Route::get('/company/settings', [CompanySettingsController::class, 'show'])->name('company.settings.show');
        Route::patch('/company/settings', [CompanySettingsController::class, 'update'])->name('company.settings.update');

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

        Route::apiResource('projects', ProjectController::class)->whereNumber('project');
        Route::post('/projects/{project}/restore', [ProjectController::class, 'restore'])->whereNumber('project')->name('projects.restore');

        Route::apiResource('activities', ActivityController::class)->whereNumber('activity');
        Route::controller(ActivityController::class)->prefix('activities/{activity}')->whereNumber('activity')->name('activities.')->group(function (): void {
            Route::post('/restore', 'restore')->name('restore');
            Route::post('/step', 'move')->name('step');
        });
        Route::controller(ActivityConversationController::class)->prefix('activities/{activity}')->whereNumber(['activity', 'message', 'item'])->name('activities.')->group(function (): void {
            Route::get('/messages', 'messages')->name('messages.index');
            Route::post('/messages', 'storeMessage')->name('messages.store');
            Route::delete('/messages/{message}', 'destroyMessage')->name('messages.destroy');
            Route::get('/context', 'context')->name('context.index');
            Route::post('/context', 'storeContext')->name('context.store');
            Route::patch('/context/{item}', 'updateContext')->name('context.update');
            Route::delete('/context/{item}', 'destroyContext')->name('context.destroy');
        });
        Route::post('/activities/{activity}/tasks', [TaskController::class, 'store'])->whereNumber('activity')->name('activities.tasks.store');

        Route::apiResource('tasks', TaskController::class)->except('store')->whereNumber('task');
        Route::controller(TaskController::class)->prefix('tasks/{task}')->whereNumber('task')->name('tasks.')->group(function (): void {
            Route::put('/agents', 'syncAgents')->name('agents');
            Route::post('/step', 'move')->name('step');
            Route::post('/confirm-completion', 'confirmCompletion')->name('confirm-completion');
        });

        Route::apiResource('rules', RuleController::class)->whereNumber('rule');
        Route::post('/rules/{rule}/extend-to-running', [RuleController::class, 'extendToRunning'])->whereNumber('rule')->name('rules.extend-to-running');
        Route::post('/rule-proposals', [RuleController::class, 'propose'])->name('rule-proposals.store');
        Route::get('/activities/{activity}/rules', [RuleController::class, 'forActivity'])->whereNumber('activity')->name('activities.rules');

        Route::get('/decisions', [DecisionController::class, 'index'])->name('decisions.index');
        Route::controller(DecisionController::class)->prefix('decisions/{decision}')->whereNumber('decision')->name('decisions.')->group(function (): void {
            Route::get('/', 'show')->name('show');
            Route::post('/approve', 'approve')->name('approve');
            Route::post('/reject', 'reject')->name('reject');
            Route::post('/request-info', 'requestInfo')->name('request-info');
            Route::post('/reply', 'reply')->name('reply');
        });

        Route::controller(OfficePlanController::class)->prefix('office')->name('office.')->group(function (): void {
            Route::get('/room-types', 'roomTypes')->name('room-types');
            Route::get('/departments', 'departments')->name('departments');
            Route::get('/plan', 'current')->name('plan');
            Route::put('/plan', 'save')->name('plan.save');
            Route::post('/plan/check', 'check')->name('plan.check');
            Route::get('/plans', 'index')->name('plans.index');
            Route::get('/plans/{plan}', 'show')->whereNumber('plan')->name('plans.show');
            Route::post('/plans/{plan}/accept', 'accept')->whereNumber('plan')->name('plans.accept');
            Route::post('/plans/{plan}/reject', 'reject')->whereNumber('plan')->name('plans.reject');
        });
        Route::apiResource('devices', DeviceController::class)->except('show')->whereNumber('device');
        Route::post('/devices/{device}/heartbeat', [DeviceController::class, 'heartbeat'])->whereNumber('device')->name('devices.heartbeat');

        Route::post('/tasks/{task}/runs', [TaskRunController::class, 'store'])->whereNumber('task')->name('tasks.runs.store');
        Route::get('/task-runs', [TaskRunController::class, 'index'])->name('task-runs.index');
        Route::controller(TaskRunController::class)->prefix('task-runs/{taskRun}')->whereNumber('taskRun')->name('task-runs.')->group(function (): void {
            Route::get('/', 'show')->name('show');
            Route::post('/usage', 'recordUsage')->name('usage');
            Route::post('/pause', 'pause')->name('pause');
            Route::post('/resume', 'resume')->name('resume');
            Route::post('/finish', 'finish')->name('finish');
        });

        Route::get('/costs/summary', [CostController::class, 'summary'])->name('costs.summary');
        Route::get('/cost-limits', [CostController::class, 'limits'])->name('cost-limits.index');
        Route::put('/cost-limits', [CostController::class, 'setLimit'])->name('cost-limits.set');

        Route::get('/agents/{agent}/tasks/{task}/context', [AgentContextController::class, 'show'])->whereNumber(['agent', 'task'])->name('agents.tasks.context');
        Route::get('/execution/tools', [ExecutionController::class, 'catalog'])->name('execution.tools');
        Route::put('/positions/{position}/tool-permissions', [ExecutionController::class, 'setPositionPermissions'])->whereNumber('position')->name('positions.tool-permissions');
        Route::get('/agents/{agent}/execution-profile', [ExecutionController::class, 'agentProfile'])->whereNumber('agent')->name('agents.execution-profile');
        Route::post('/task-runs/{taskRun}/approvals', [ExecutionController::class, 'requestApproval'])->whereNumber('taskRun')->name('task-runs.approvals');
        Route::get('/projects/{project}/folders', [ExecutionController::class, 'folders'])->whereNumber('project')->name('projects.folders');
        Route::put('/projects/{project}/folders', [ExecutionController::class, 'setFolders'])->whereNumber('project')->name('projects.folders.set');
    });
});
