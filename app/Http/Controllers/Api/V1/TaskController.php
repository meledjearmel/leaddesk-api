<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StepCategory;
use App\Http\Resources\V1\TaskResource;
use App\Models\Activity;
use App\Models\Task;
use App\Support\ActingAgent;
use App\Support\Audit;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tâches : les parts de travail d'une activité, confiées chacune à une équipe.
 */
#[Group('Tâches', weight: 12)]
class TaskController extends ApiController
{
    public function __construct(
        private readonly ActingAgent $actingAgent,
        private readonly Audit $audit,
    ) {}

    /**
     * Lister les tâches.
     *
     * Filtrable par activité, équipe et agent affecté.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'activity_id' => ['sometimes', 'integer'],
            'unit_id' => ['sometimes', 'integer'],
            'agent_id' => ['sometimes', 'integer'],
        ]);

        $query = $this->company($request)->tasks()->with(['agents', 'step']);

        if (isset($filters['activity_id'])) {
            $query->where('activity_id', $filters['activity_id']);
        }

        if (isset($filters['unit_id'])) {
            $query->where('unit_id', $filters['unit_id']);
        }

        if (isset($filters['agent_id'])) {
            $query->whereHas('agents', fn ($agents) => $agents->whereKey($filters['agent_id']));
        }

        return TaskResource::collection($query->orderBy('id')->get());
    }

    /**
     * Ajouter une tâche à une activité.
     *
     * La tâche démarre à la première étape du workflow de tâche du type.
     * `unit_id` peut être omis quand l'activité ne mobilise qu'une équipe.
     */
    public function store(Request $request, int $activity): JsonResponse
    {
        $parent = $this->company($request)->activities()->findOrFail($activity);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            // Équipe de la tâche, parmi les équipes de l'activité.
            'unit_id' => ['sometimes', 'integer'],
        ]);

        $task = $parent->tasks()->create([
            'company_id' => $parent->company_id,
            'unit_id' => $this->resolveUnit($parent, $data['unit_id'] ?? null),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'workflow_step_id' => $parent->type->taskWorkflow?->initialStep()?->id,
        ]);

        return (new TaskResource($task->refresh()->load(['agents', 'step'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Lire une tâche.
     */
    public function show(Request $request, int $task): TaskResource
    {
        return new TaskResource($this->find($request, $task)->load(['agents', 'step']));
    }

    /**
     * Modifier une tâche.
     */
    public function update(Request $request, int $task): TaskResource
    {
        $model = $this->find($request, $task);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'unit_id' => ['sometimes', 'integer'],
        ]);

        if (isset($data['unit_id'])) {
            $data['unit_id'] = $this->resolveUnit($model->activity, $data['unit_id']);
        }

        $model->update($data);

        return new TaskResource($model->refresh()->load(['agents', 'step']));
    }

    /**
     * Mettre une tâche à la corbeille.
     */
    public function destroy(Request $request, int $task): Response
    {
        $this->find($request, $task)->delete();

        return response()->noContent();
    }

    /**
     * Affecter des agents à une tâche.
     *
     * Remplace la liste : un agent principal et des collaborateurs, ou
     * plusieurs agents ensemble. Quand un agent fait la demande, il doit être
     * le responsable de l'équipe de la tâche (RM-08).
     */
    public function syncAgents(Request $request, int $task): TaskResource
    {
        $company = $this->company($request);
        $model = $this->find($request, $task);
        $this->ensureActorLeadsTheTeam($model);

        $data = $request->validate([
            'agents' => ['present', 'array', 'max:50'],
            'agents.*.agent_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('agents', 'id')->where('company_id', $company->id)->whereNull('deleted_at'),
            ],
            // Un seul agent principal au plus.
            'agents.*.is_lead' => ['sometimes', 'boolean'],
        ]);

        /** @var list<array{agent_id: int, is_lead?: bool}> $agents */
        $agents = $data['agents'];

        if (collect($agents)->where('is_lead', true)->count() > 1) {
            throw ValidationException::withMessages([
                'agents' => ['Une tâche a au plus un agent principal.'],
            ]);
        }

        $sync = [];

        foreach ($agents as $entry) {
            $sync[$entry['agent_id']] = ['is_lead' => $entry['is_lead'] ?? false];
        }

        $changes = $model->agents()->sync($sync);

        $this->audit->record($company, 'task.agents_changed', $model, [
            'attached' => $changes['attached'],
            'detached' => $changes['detached'],
            'updated' => $changes['updated'],
        ]);

        return new TaskResource($model->load(['agents', 'step']));
    }

    /**
     * Faire avancer une tâche.
     *
     * L'étape doit appartenir au workflow de tâche actuel du type. Une étape
     * de catégorie `done` ne s'atteint que par la confirmation du responsable
     * (RM-19).
     */
    public function move(Request $request, int $task): TaskResource
    {
        $model = $this->find($request, $task);
        $data = $request->validate(['workflow_step_id' => ['required', 'integer']]);

        $step = $model->activity->type->taskWorkflow?->steps()->whereKey($data['workflow_step_id'])->first();

        if ($step === null) {
            throw ValidationException::withMessages([
                'workflow_step_id' => ["Cette étape n'appartient pas au workflow de tâche de ce type d'activité."],
            ]);
        }

        if ($step->category === StepCategory::Done) {
            throw new ConflictHttpException(
                "La fin d'une tâche se confirme : seul le responsable de l'équipe peut la déclarer terminée.",
            );
        }

        // Rouvrir une tâche terminée efface sa confirmation.
        $model->update([
            'workflow_step_id' => $step->id,
            'completed_at' => null,
            'confirmed_by_agent_id' => null,
            'confirmed_by_ceo' => false,
        ]);

        return new TaskResource($model->refresh()->load(['agents', 'step']));
    }

    /**
     * Confirmer la fin d'une tâche.
     *
     * Réservé au responsable de l'équipe de la tâche (RM-19) : l'agent en
     * service qui occupe le poste de responsable de l'unité, ou à défaut celui
     * de l'unité parente la plus proche. Le CEO ne confirme lui-même que si
     * aucune de ces unités n'a de responsable en service.
     */
    public function confirmCompletion(Request $request, int $task): TaskResource
    {
        $model = $this->find($request, $task);
        $responsible = $model->unit->responsibleAgent();
        $actor = $this->actingAgent->get();

        if ($responsible !== null && $actor?->id !== $responsible->id) {
            throw new AccessDeniedHttpException(
                "Seul {$responsible->name}, responsable de l'équipe, peut confirmer la fin de cette tâche.",
            );
        }

        if ($responsible === null && $actor !== null) {
            throw new AccessDeniedHttpException(
                "Cette équipe n'a pas de responsable en service : seul le CEO peut confirmer la fin de cette tâche.",
            );
        }

        $done = $model->activity->type->taskWorkflow?->steps()->where('category', StepCategory::Done)->first();

        if ($done === null) {
            throw new ConflictHttpException(
                "Le workflow de tâche de ce type d'activité n'a pas d'étape terminée.",
            );
        }

        if ($model->completed_at !== null) {
            throw new ConflictHttpException('La fin de cette tâche est déjà confirmée.');
        }

        $model->update([
            'workflow_step_id' => $done->id,
            'completed_at' => now(),
            'confirmed_by_agent_id' => $responsible?->id,
            'confirmed_by_ceo' => $responsible === null,
        ]);

        $this->audit->record($model->company_id, 'task.completion_confirmed', $model, [
            'confirmed_by_agent_id' => $responsible?->id,
            'confirmed_by_ceo' => $responsible === null,
        ]);

        return new TaskResource($model->refresh()->load(['agents', 'step']));
    }

    private function find(Request $request, int $id): Task
    {
        return $this->company($request)->tasks()->findOrFail($id);
    }

    /**
     * Équipe d'une tâche : une des équipes de l'activité, ou la seule quand il
     * n'y en a qu'une.
     */
    private function resolveUnit(Activity $activity, ?int $unitId): int
    {
        /** @var list<int> $teams */
        $teams = $activity->units()->pluck('units.id')->all();

        if ($unitId === null && count($teams) === 1) {
            return $teams[0];
        }

        if ($unitId === null || ! in_array($unitId, $teams, true)) {
            throw ValidationException::withMessages([
                'unit_id' => ["Choisissez une des équipes mobilisées par l'activité."],
            ]);
        }

        return $unitId;
    }

    /**
     * RM-08 : les responsables affectent les agents aux tâches de leur équipe.
     * Le CEO, lui, peut toujours le faire.
     */
    private function ensureActorLeadsTheTeam(Task $task): void
    {
        $actor = $this->actingAgent->get();

        if ($actor !== null && $task->unit->responsibleAgent()?->id !== $actor->id) {
            throw new AccessDeniedHttpException(
                "Seul le responsable de l'équipe affecte les agents à ses tâches.",
            );
        }
    }
}
