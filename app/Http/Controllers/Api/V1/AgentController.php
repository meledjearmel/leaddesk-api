<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Agents\AssignAgentToPosition;
use App\Enums\LifecycleStatus;
use App\Http\Resources\V1\AgentResource;
use App\Http\Resources\V1\AssignmentResource;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\Company;
use App\Support\Audit;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Agents : identité, poste, parcours, modèles IA et mise en service.
 */
#[Group('Agents', weight: 5)]
class AgentController extends ApiController
{
    /**
     * Lister les agents.
     *
     * Filtrable par poste et par état. Avec `trashed=1`, renvoie la corbeille à la place.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'position_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(LifecycleStatus::class)],
            'trashed' => ['sometimes', 'boolean'],
        ]);

        $query = $this->company($request)->agents()->with('aiModels');

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        if (isset($filters['position_id'])) {
            $query->where('position_id', $filters['position_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return AgentResource::collection($query->orderBy('id')->get());
    }

    /**
     * Créer un agent.
     *
     * L'agent est créé en brouillon. S'il reçoit un poste, son parcours commence.
     */
    public function store(Request $request, AssignAgentToPosition $assign): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Poste de l'agent, de la même entreprise.
            'position_id' => ['sometimes', 'nullable', 'integer', $this->positionRule($company)],
        ]);

        $agent = DB::transaction(function () use ($company, $data, $assign): Agent {
            $agent = $company->agents()->create([
                'name' => $data['name'],
                'bio' => $data['bio'] ?? null,
                'status' => LifecycleStatus::Draft,
            ]);

            if (isset($data['position_id'])) {
                $assign->handle($agent, $company->positions()->whereKey($data['position_id'])->firstOrFail());
            }

            return $agent;
        });

        return (new AgentResource($agent->refresh()->load('aiModels')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Lire un agent.
     */
    public function show(Request $request, int $agent): AgentResource
    {
        return new AgentResource($this->find($request, $agent)->load('aiModels'));
    }

    /**
     * Modifier l'identité d'un agent.
     *
     * Le poste se change par la route dédiée, l'état par la mise en service.
     */
    public function update(Request $request, int $agent): AgentResource
    {
        $model = $this->find($request, $agent);

        $model->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]));

        return new AgentResource($model->load('aiModels'));
    }

    /**
     * Mettre un agent à la corbeille.
     *
     * Son identité et son parcours sont conservés.
     */
    public function destroy(Request $request, int $agent): Response
    {
        $this->find($request, $agent)->delete();

        return response()->noContent();
    }

    /**
     * Restaurer un agent depuis la corbeille.
     *
     * Refusé (409) si son poste est à la corbeille.
     */
    public function restore(Request $request, int $agent): AgentResource
    {
        $company = $this->company($request);
        $model = $company->agents()->onlyTrashed()->findOrFail($agent);

        if ($model->position_id !== null && ! $company->positions()->whereKey($model->position_id)->exists()) {
            throw new ConflictHttpException("Le poste de cet agent est à la corbeille : restaurez-le d'abord.");
        }

        $model->restore();

        return new AgentResource($model->load('aiModels'));
    }

    /**
     * Changer le poste d'un agent.
     *
     * L'agent garde son identité et son historique (RM-05) : l'affectation en
     * cours est close et une nouvelle commence. `position_id` vide retire
     * l'agent de tout poste.
     */
    public function changePosition(Request $request, int $agent, AssignAgentToPosition $assign): AgentResource
    {
        $company = $this->company($request);
        $model = $this->find($request, $agent);
        $data = $request->validate([
            'position_id' => ['present', 'nullable', 'integer', $this->positionRule($company)],
        ]);

        $position = $data['position_id'] === null
            ? null
            : $company->positions()->whereKey($data['position_id'])->firstOrFail();

        return new AgentResource($assign->handle($model, $position)->load('aiModels'));
    }

    /**
     * Lire le parcours d'un agent.
     *
     * Toutes ses affectations, de la plus récente à la plus ancienne.
     */
    public function assignments(Request $request, int $agent): AnonymousResourceCollection
    {
        return AssignmentResource::collection(
            $this->find($request, $agent)->assignments()->with('position')->get(),
        );
    }

    /**
     * Affecter des modèles IA à un agent.
     *
     * Remplace la liste des modèles de l'agent (RM-10). Un agent en service
     * doit garder au moins un modèle (RM-06).
     */
    public function syncAiModels(Request $request, int $agent): AgentResource
    {
        $company = $this->company($request);
        $model = $this->find($request, $agent);
        $data = $request->validate([
            'models' => ['present', 'array', 'max:20'],
            'models.*.ai_model_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('ai_models', 'id')->where('company_id', $company->id)->whereNull('deleted_at'),
            ],
            // Usage prévu de ce modèle pour cet agent.
            'models.*.purpose' => ['sometimes', 'nullable', 'string', 'max:160'],
            // Un seul modèle par défaut.
            'models.*.is_default' => ['sometimes', 'boolean'],
        ]);

        /** @var list<array{ai_model_id: int, purpose?: string|null, is_default?: bool}> $models */
        $models = $data['models'];

        if (collect($models)->where('is_default', true)->count() > 1) {
            throw ValidationException::withMessages([
                'models' => ['Un seul modèle peut être le modèle par défaut.'],
            ]);
        }

        if ($model->status === LifecycleStatus::Active && ! $this->hasUsableModel($models)) {
            throw new ConflictHttpException(
                "Un agent en service doit garder au moins un modèle IA : repassez-le d'abord en brouillon.",
            );
        }

        $sync = [];

        foreach ($models as $entry) {
            $sync[$entry['ai_model_id']] = [
                'purpose' => $entry['purpose'] ?? null,
                'is_default' => $entry['is_default'] ?? false,
            ];
        }

        $changes = $model->aiModels()->sync($sync);

        app(Audit::class)->record($company, 'agent.ai_models_changed', $model, [
            'attached' => $changes['attached'],
            'detached' => $changes['detached'],
            'updated' => $changes['updated'],
        ]);

        return new AgentResource($model->load('aiModels'));
    }

    /**
     * Mettre un agent en service.
     *
     * Possible dès qu'un modèle IA lui est configuré ; aucun accès MCP n'est
     * exigé (RM-06). Refusé (409) sans modèle utilisable.
     */
    public function activate(Request $request, int $agent): AgentResource
    {
        $model = $this->find($request, $agent);

        if (! $model->hasUsableAiModel()) {
            throw new ConflictHttpException("Configurez d'abord un modèle IA pour cet agent.");
        }

        $model->update(['status' => LifecycleStatus::Active]);

        return new AgentResource($model->load('aiModels'));
    }

    /**
     * Repasser un agent en brouillon.
     */
    public function deactivate(Request $request, int $agent): AgentResource
    {
        $model = $this->find($request, $agent);
        $model->update(['status' => LifecycleStatus::Draft]);

        return new AgentResource($model->load('aiModels'));
    }

    private function find(Request $request, int $id): Agent
    {
        return $this->company($request)->agents()->findOrFail($id);
    }

    private function positionRule(Company $company): Exists
    {
        return Rule::exists('positions', 'id')->where('company_id', $company->id)->whereNull('deleted_at');
    }

    /**
     * @param  list<array{ai_model_id: int, purpose?: string|null, is_default?: bool}>  $models
     */
    private function hasUsableModel(array $models): bool
    {
        return $models !== [] && AiModel::query()
            ->whereKey(array_column($models, 'ai_model_id'))
            ->whereHas('provider')
            ->exists();
    }
}
