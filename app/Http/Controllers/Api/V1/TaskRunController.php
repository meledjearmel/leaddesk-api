<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Costs\RecordUsage;
use App\Enums\LifecycleStatus;
use App\Enums\RunStatus;
use App\Http\Resources\V1\TaskRunResource;
use App\Models\TaskRun;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Exécutions de tâche : un agent travaille sur une tâche, sur un ordinateur
 * hôte. Le serveur n'en garde que l'état et les coûts, jamais les fichiers.
 */
#[Group('Exécutions de tâche', weight: 16)]
class TaskRunController extends ApiController
{
    /**
     * Lister les exécutions.
     *
     * Filtrable par tâche, ordinateur hôte, et avec `open=1` pour celles qui
     * ne sont pas terminées. Les plus récentes d'abord.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'task_id' => ['sometimes', 'integer'],
            'device_id' => ['sometimes', 'integer'],
            'open' => ['sometimes', 'boolean'],
        ]);

        $query = $this->company($request)->taskRuns()->with('device')->orderByDesc('id');

        if (isset($filters['task_id'])) {
            $query->where('task_id', $filters['task_id']);
        }

        if (isset($filters['device_id'])) {
            $query->where('device_id', $filters['device_id']);
        }

        if ($request->boolean('open')) {
            $query->whereNull('ended_at');
        }

        return TaskRunResource::collection($query->get());
    }

    /**
     * Démarrer l'exécution d'une tâche.
     *
     * L'agent doit être en service et affecté à la tâche ; l'ordinateur
     * devient l'hôte de l'exécution. Une tâche n'a qu'une exécution ouverte.
     */
    public function store(Request $request, int $task): JsonResponse
    {
        $company = $this->company($request);
        $model = $company->tasks()->findOrFail($task);
        $data = $request->validate([
            'agent_id' => [
                'required', 'integer',
                Rule::exists('agents', 'id')->where('company_id', $company->id)->whereNull('deleted_at'),
            ],
            'device_id' => [
                'required', 'integer',
                Rule::exists('devices', 'id')->where('company_id', $company->id)->whereNull('deleted_at'),
            ],
        ]);

        $agent = $company->agents()->whereKey($data['agent_id'])->firstOrFail();

        if ($agent->status !== LifecycleStatus::Active) {
            throw ValidationException::withMessages(['agent_id' => ["Cet agent n'est pas en service."]]);
        }

        if (! $model->agents()->whereKey($agent->id)->exists()) {
            throw ValidationException::withMessages(['agent_id' => ["Cet agent n'est pas affecté à la tâche."]]);
        }

        if ($model->completed_at !== null) {
            throw new ConflictHttpException('La fin de cette tâche est déjà confirmée.');
        }

        if ($company->taskRuns()->where('task_id', $model->id)->whereNull('ended_at')->exists()) {
            throw new ConflictHttpException('Cette tâche a déjà une exécution en cours.');
        }

        $run = $company->taskRuns()->create([
            'task_id' => $model->id,
            'agent_id' => $agent->id,
            'device_id' => $data['device_id'],
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);

        return (new TaskRunResource($run->load('device')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Lire une exécution.
     */
    public function show(Request $request, int $taskRun): TaskRunResource
    {
        return new TaskRunResource($this->find($request, $taskRun)->load('device'));
    }

    /**
     * Enregistrer le coût réel d'un appel.
     *
     * Appelé par l'ordinateur hôte après chaque appel de modèle ou d'outil
     * (RM-35). Si une limite de coût est atteinte, l'exécution renvoyée est en
     * pause (`paused_cost`) et une décision attend le CEO (RM-36) : l'hôte
     * doit alors s'arrêter.
     */
    public function recordUsage(Request $request, int $taskRun, RecordUsage $recordUsage): TaskRunResource
    {
        $run = $this->find($request, $taskRun);

        if (! $run->status->isOpen()) {
            throw new ConflictHttpException('Cette exécution est terminée.');
        }

        /** @var array{kind?: string, provider?: string|null, model?: string|null, input_tokens?: int, output_tokens?: int, cache_read_tokens?: int, cache_write_tokens?: int, cost_micros?: int|null, occurred_at?: string|null} $data */
        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(['model', 'tool'])],
            // Fournisseur et modèle qui ont réellement répondu.
            'provider' => ['sometimes', 'nullable', 'string', 'max:40'],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'input_tokens' => ['sometimes', 'integer', 'min:0'],
            'output_tokens' => ['sometimes', 'integer', 'min:0'],
            'cache_read_tokens' => ['sometimes', 'integer', 'min:0'],
            'cache_write_tokens' => ['sometimes', 'integer', 'min:0'],
            // Coût réel en millionièmes de dollar ; vide si le tarif est inconnu.
            'cost_micros' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'occurred_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $recordUsage->handle($run, $data);

        return new TaskRunResource($run->refresh()->load('device'));
    }

    /**
     * Mettre une exécution en pause.
     */
    public function pause(Request $request, int $taskRun): TaskRunResource
    {
        $run = $this->openRun($request, $taskRun);

        if ($run->status === RunStatus::Running) {
            $run->update(['status' => RunStatus::Paused, 'status_reason' => 'Mise en pause par le CEO.']);
        }

        return new TaskRunResource($run->load('device'));
    }

    /**
     * Relancer une exécution en pause.
     *
     * Une exécution en pause pour limite de coût ne se relance pas ici : elle
     * attend la décision du CEO sur la limite (RM-36).
     */
    public function resume(Request $request, int $taskRun): TaskRunResource
    {
        $run = $this->openRun($request, $taskRun);

        if ($run->status === RunStatus::PausedCost) {
            throw new ConflictHttpException(
                'Limite de coût atteinte : cette exécution attend la décision du CEO sur la limite.',
            );
        }

        $run->update(['status' => RunStatus::Running, 'status_reason' => null]);

        return new TaskRunResource($run->load('device'));
    }

    /**
     * Terminer une exécution.
     *
     * `outcome` : `completed`, `failed` ou `cancelled`. Terminer l'exécution
     * ne termine pas la tâche : sa fin se confirme par le responsable (RM-19).
     */
    public function finish(Request $request, int $taskRun): TaskRunResource
    {
        $run = $this->openRun($request, $taskRun);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['completed', 'failed', 'cancelled'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $run->update([
            'status' => $data['outcome'],
            'status_reason' => $data['reason'] ?? null,
            'ended_at' => now(),
        ]);

        return new TaskRunResource($run->load('device'));
    }

    private function find(Request $request, int $id): TaskRun
    {
        return $this->company($request)->taskRuns()->findOrFail($id);
    }

    private function openRun(Request $request, int $id): TaskRun
    {
        $run = $this->find($request, $id);

        if (! $run->status->isOpen()) {
            throw new ConflictHttpException('Cette exécution est terminée.');
        }

        return $run;
    }
}
