<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Work\RealignWorkflowSteps;
use App\Enums\StepCategory;
use App\Enums\WorkflowScope;
use App\Http\Resources\V1\WorkflowResource;
use App\Models\Workflow;
use App\Support\Audit;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Workflows : parcours configurables, distincts pour les activités et pour
 * les tâches (RM-18). Rien n'est codé en dur : libellés, nombre et ordre des
 * étapes appartiennent à l'entreprise (RM-03).
 */
#[Group('Workflows', weight: 8)]
class WorkflowController extends ApiController
{
    /**
     * Lister les workflows.
     *
     * Filtrable par portée : `activity` ou `task`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'scope' => ['sometimes', Rule::enum(WorkflowScope::class)],
        ]);

        $query = $this->company($request)->workflows()->with('steps');

        if (isset($filters['scope'])) {
            $query->where('scope', $filters['scope']);
        }

        return WorkflowResource::collection($query->orderBy('id')->get());
    }

    /**
     * Créer un workflow.
     *
     * Les étapes sont données dans l'ordre du parcours ; la première est
     * l'étape de départ.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Portée du workflow ; elle ne change plus ensuite.
            'scope' => ['required', Rule::enum(WorkflowScope::class)],
            'steps' => ['required', 'array', 'min:1', 'max:30'],
            'steps.*.name' => ['required', 'string', 'max:80'],
            'steps.*.category' => ['required', Rule::enum(StepCategory::class)],
        ]);

        /** @var list<array{name: string, category: string}> $steps */
        $steps = $data['steps'];

        $workflow = DB::transaction(function () use ($request, $data, $steps): Workflow {
            $workflow = $this->company($request)->workflows()->create([
                'name' => $data['name'],
                'scope' => $data['scope'],
            ]);

            foreach ($steps as $order => $step) {
                $workflow->steps()->create([...$step, 'sort_order' => $order]);
            }

            return $workflow;
        });

        return (new WorkflowResource($workflow->load('steps')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Lire un workflow.
     */
    public function show(Request $request, int $workflow): WorkflowResource
    {
        return new WorkflowResource($this->find($request, $workflow)->load('steps'));
    }

    /**
     * Modifier un workflow.
     *
     * Quand `steps` est fourni, il remplace le parcours : une étape envoyée
     * avec son `id` est conservée (renommée, déplacée), une étape sans `id`
     * est créée, une étape absente est retirée.
     */
    public function update(Request $request, int $workflow, RealignWorkflowSteps $realign): WorkflowResource
    {
        $model = $this->find($request, $workflow);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'steps' => ['sometimes', 'array', 'min:1', 'max:30'],
            // Identifiant d'une étape existante de ce workflow, pour la conserver.
            'steps.*.id' => ['sometimes', 'integer', 'distinct'],
            'steps.*.name' => ['required', 'string', 'max:80'],
            'steps.*.category' => ['required', Rule::enum(StepCategory::class)],
        ]);

        DB::transaction(function () use ($model, $data, $realign): void {
            if (isset($data['name'])) {
                $model->update(['name' => $data['name']]);
            }

            if (isset($data['steps'])) {
                /** @var list<array{id?: int, name: string, category: string}> $steps */
                $steps = $data['steps'];
                $this->replaceSteps($model, $steps);
                // Les éléments posés sur une étape retirée changent d'étape (RM-21).
                $realign->forWorkflow($model);
            }
        });

        return new WorkflowResource($model->load('steps'));
    }

    /**
     * Mettre un workflow à la corbeille.
     */
    public function destroy(Request $request, int $workflow): Response
    {
        $this->find($request, $workflow)->delete();

        return response()->noContent();
    }

    /**
     * @param  list<array{id?: int, name: string, category: string}>  $steps
     */
    private function replaceSteps(Workflow $workflow, array $steps): void
    {
        $existing = $workflow->steps()->get()->keyBy('id');
        $kept = [];

        foreach ($steps as $order => $step) {
            $attributes = ['name' => $step['name'], 'category' => $step['category'], 'sort_order' => $order];

            if (! isset($step['id'])) {
                $kept[] = $workflow->steps()->create($attributes)->id;

                continue;
            }

            $current = $existing->get($step['id']);

            if ($current === null) {
                throw ValidationException::withMessages([
                    "steps.{$order}.id" => ["Cette étape n'appartient pas à ce workflow."],
                ]);
            }

            $current->update($attributes);
            $kept[] = $current->id;
        }

        $removed = $existing->keys()->diff($kept)->values()->all();
        $workflow->steps()->whereKey($removed)->delete();

        app(Audit::class)->record($workflow->company_id, 'workflow.steps_changed', $workflow, [
            'steps' => array_map(fn (array $step): string => $step['name'], $steps),
            'removed_step_ids' => $removed,
        ]);
    }

    private function find(Request $request, int $id): Workflow
    {
        return $this->company($request)->workflows()->findOrFail($id);
    }
}
