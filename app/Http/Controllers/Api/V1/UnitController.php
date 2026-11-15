<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\UnitResource;
use App\Models\Company;
use App\Models\Unit;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Organisation : unités hiérarchiques imbriquées.
 */
#[Group('Organisation', weight: 3)]
class UnitController extends ApiController
{
    /**
     * Lister les unités.
     *
     * Liste à plat, triée ; l'arbre se reconstruit avec `parent_id`.
     * Avec `trashed=1`, renvoie la corbeille à la place.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['trashed' => ['sometimes', 'boolean']]);

        $query = $this->company($request)->units();

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return UnitResource::collection($query->orderBy('sort_order')->orderBy('id')->get());
    }

    /**
     * Créer une unité.
     */
    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $unit = $company->units()->create($this->validated($request, $company));

        return (new UnitResource($unit->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Lire une unité.
     */
    public function show(Request $request, int $unit): UnitResource
    {
        return new UnitResource($this->find($request, $unit));
    }

    /**
     * Modifier une unité.
     *
     * Une unité ne peut pas être déplacée sous elle-même ni sous une de ses sous-unités.
     */
    public function update(Request $request, int $unit): UnitResource
    {
        $company = $this->company($request);
        $model = $this->find($request, $unit);
        $data = $this->validated($request, $company, partial: true);

        if (isset($data['parent_id'])) {
            $parent = $company->units()->whereKey($data['parent_id'])->firstOrFail();

            if ($parent->isSelfOrDescendantOf($model)) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Une unité ne peut pas être placée sous elle-même ni sous une de ses sous-unités.'],
                ]);
            }
        }

        $model->update($data);

        return new UnitResource($model);
    }

    /**
     * Mettre une unité à la corbeille.
     *
     * Refusé (409) pour une unité système (RH, Moyens généraux) et tant que
     * l'unité contient des sous-unités ou des postes.
     */
    public function destroy(Request $request, int $unit): Response
    {
        $model = $this->find($request, $unit);

        if ($model->system_role !== null) {
            throw new ConflictHttpException('Cette unité existe dans toute entreprise : elle ne peut pas être supprimée.');
        }

        if ($model->children()->exists() || $model->positions()->exists()) {
            throw new ConflictHttpException('Cette unité contient encore des sous-unités ou des postes.');
        }

        $model->delete();

        return response()->noContent();
    }

    /**
     * Restaurer une unité depuis la corbeille.
     *
     * Refusé (409) si son unité parente est elle-même à la corbeille.
     */
    public function restore(Request $request, int $unit): UnitResource
    {
        $company = $this->company($request);
        $model = $company->units()->onlyTrashed()->findOrFail($unit);

        if ($model->parent_id !== null && ! $company->units()->whereKey($model->parent_id)->exists()) {
            throw new ConflictHttpException("L'unité parente est à la corbeille : restaurez-la d'abord.");
        }

        $model->restore();

        return new UnitResource($model);
    }

    private function find(Request $request, int $id): Unit
    {
        return $this->company($request)->units()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Company $company, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            // Unité parente, de la même entreprise ; vide pour le premier niveau.
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('units', 'id')->where('company_id', $company->id)->whereNull('deleted_at'),
            ],
            // Libellé libre du niveau, par exemple « Département ».
            'kind' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Couleur au format #RRGGBB.
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
    }
}
