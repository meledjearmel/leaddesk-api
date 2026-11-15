<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\AiModelResource;
use App\Http\Resources\V1\AiProviderResource;
use App\Models\AiModel;
use App\Models\AiProvider;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Fournisseurs de modèles IA et leurs modèles (RM-10).
 *
 * Le serveur ne reçoit et ne stocke jamais de clé d'API : elle reste dans le
 * stockage sécurisé de l'ordinateur du CEO.
 */
#[Group('Modèles IA', weight: 6)]
class AiProviderController extends ApiController
{
    /**
     * Lire le catalogue des types de fournisseurs.
     *
     * Aide à la saisie : un type absent du catalogue reste accepté.
     *
     * @return array{data: list<array{driver: string, label: string}>}
     */
    public function catalog(): array
    {
        /** @var array<string, string> $drivers */
        $drivers = config('leaddesk.ai_drivers');
        $data = [];

        foreach ($drivers as $driver => $label) {
            $data[] = ['driver' => $driver, 'label' => $label];
        }

        return ['data' => $data];
    }

    /**
     * Lister les fournisseurs et leurs modèles.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return AiProviderResource::collection(
            $this->company($request)->aiProviders()->with('models')->orderBy('id')->get(),
        );
    }

    /**
     * Connecter un fournisseur.
     */
    public function store(Request $request): JsonResponse
    {
        $provider = $this->company($request)->aiProviders()->create($this->validated($request));

        return (new AiProviderResource($provider->load('models')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Lire un fournisseur.
     */
    public function show(Request $request, int $aiProvider): AiProviderResource
    {
        return new AiProviderResource($this->find($request, $aiProvider)->load('models'));
    }

    /**
     * Modifier un fournisseur.
     */
    public function update(Request $request, int $aiProvider): AiProviderResource
    {
        $provider = $this->find($request, $aiProvider);
        $provider->update($this->validated($request, partial: true));

        return new AiProviderResource($provider->load('models'));
    }

    /**
     * Retirer un fournisseur.
     *
     * Refusé (409) si un agent en service n'a que ses modèles pour travailler.
     */
    public function destroy(Request $request, int $aiProvider): Response
    {
        $provider = $this->find($request, $aiProvider);
        /** @var list<int> $modelIds */
        $modelIds = $provider->models()->pluck('id')->all();

        if ($modelIds !== [] && AiModel::wouldStrandActiveAgents($modelIds)) {
            throw new ConflictHttpException(
                'Un agent en service dépend uniquement de ce fournisseur : donnez-lui un autre modèle ou repassez-le en brouillon.',
            );
        }

        $provider->delete();

        return response()->noContent();
    }

    /**
     * Ajouter un modèle à un fournisseur.
     */
    public function storeModel(Request $request, int $aiProvider): JsonResponse
    {
        $provider = $this->find($request, $aiProvider);
        $data = $request->validate([
            // Identifiant du modèle chez le fournisseur, unique pour ce fournisseur.
            'identifier' => [
                'required', 'string', 'max:120',
                Rule::unique('ai_models', 'identifier')
                    ->where('ai_provider_id', $provider->id)
                    ->whereNull('deleted_at'),
            ],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $model = $provider->models()->create([...$data, 'company_id' => $provider->company_id]);

        return (new AiModelResource($model))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Renommer un modèle.
     */
    public function updateModel(Request $request, int $aiModel): AiModelResource
    {
        $model = $this->findModel($request, $aiModel);
        $model->update($request->validate([
            'label' => ['present', 'nullable', 'string', 'max:120'],
        ]));

        return new AiModelResource($model);
    }

    /**
     * Retirer un modèle.
     *
     * Refusé (409) si un agent en service n'a que ce modèle pour travailler.
     */
    public function destroyModel(Request $request, int $aiModel): Response
    {
        $model = $this->findModel($request, $aiModel);

        if (AiModel::wouldStrandActiveAgents([$model->id])) {
            throw new ConflictHttpException(
                'Un agent en service dépend uniquement de ce modèle : donnez-lui un autre modèle ou repassez-le en brouillon.',
            );
        }

        $model->agents()->detach();
        $model->delete();

        return response()->noContent();
    }

    private function find(Request $request, int $id): AiProvider
    {
        return $this->company($request)->aiProviders()->findOrFail($id);
    }

    private function findModel(Request $request, int $id): AiModel
    {
        return AiModel::query()
            ->where('company_id', $this->company($request)->id)
            ->whereHas('provider')
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:80'],
            // Type de source en minuscules, par exemple « anthropic ».
            'driver' => [$required, 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            // Adresse de l'API si elle diffère de celle par défaut du type.
            'base_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
        ]);
    }
}
