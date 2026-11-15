<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LifecycleStatus;
use App\Http\Resources\V1\PositionResource;
use App\Models\Company;
use App\Models\Position;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Postes : fonction, mission, responsabilités et place hiérarchique.
 */
#[Group('Postes', weight: 4)]
class PositionController extends ApiController
{
    /**
     * Lister les postes.
     *
     * Filtrable par unité. Avec `trashed=1`, renvoie la corbeille à la place.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'unit_id' => ['sometimes', 'integer'],
            'trashed' => ['sometimes', 'boolean'],
        ]);

        $query = $this->company($request)->positions();

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        if (isset($filters['unit_id'])) {
            $query->where('unit_id', $filters['unit_id']);
        }

        return PositionResource::collection($query->orderBy('sort_order')->orderBy('id')->get());
    }

    /**
     * Créer un poste.
     *
     * Le poste est en service (`active`) sauf si `status` vaut `draft`.
     */
    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $position = $company->positions()->create($this->validated($request, $company));

        return (new PositionResource($position->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Lire un poste.
     */
    public function show(Request $request, int $position): PositionResource
    {
        return new PositionResource($this->find($request, $position));
    }

    /**
     * Modifier un poste.
     *
     * Un poste ne peut pas rendre compte à lui-même ni à un poste qui dépend de lui.
     */
    public function update(Request $request, int $position): PositionResource
    {
        $company = $this->company($request);
        $model = $this->find($request, $position);
        $data = $this->validated($request, $company, partial: true);

        if (isset($data['reports_to_id'])) {
            $superior = $company->positions()->whereKey($data['reports_to_id'])->firstOrFail();

            if ($superior->isSelfOrReportsTo($model)) {
                throw ValidationException::withMessages([
                    'reports_to_id' => ['Un poste ne peut pas rendre compte à lui-même ni à un poste qui dépend de lui.'],
                ]);
            }
        }

        $model->update($data);

        return new PositionResource($model);
    }

    /**
     * Mettre un poste à la corbeille.
     *
     * Refusé (409) tant que d'autres postes lui rendent compte ou qu'un agent l'occupe.
     */
    public function destroy(Request $request, int $position): Response
    {
        $model = $this->find($request, $position);

        if ($model->directReports()->exists()) {
            throw new ConflictHttpException("D'autres postes rendent compte à ce poste.");
        }

        if ($model->agents()->exists()) {
            throw new ConflictHttpException('Un agent occupe ce poste : changez-le de poste avant.');
        }

        $model->delete();

        return response()->noContent();
    }

    /**
     * Restaurer un poste depuis la corbeille.
     *
     * Refusé (409) si son unité est à la corbeille.
     */
    public function restore(Request $request, int $position): PositionResource
    {
        $company = $this->company($request);
        $model = $company->positions()->onlyTrashed()->findOrFail($position);

        if (! $company->units()->whereKey($model->unit_id)->exists()) {
            throw new ConflictHttpException("L'unité de ce poste est à la corbeille : restaurez-la d'abord.");
        }

        $model->restore();

        return new PositionResource($model);
    }

    private function find(Request $request, int $id): Position
    {
        return $this->company($request)->positions()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Company $company, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $sameCompany = fn (string $table) => Rule::exists($table, 'id')
            ->where('company_id', $company->id)
            ->whereNull('deleted_at');

        return $request->validate([
            'title' => [$required, 'string', 'max:120'],
            // Unité du poste, de la même entreprise.
            'unit_id' => [$required, 'integer', $sameCompany('units')],
            // Poste supérieur ; vide quand le poste rend compte au CEO.
            'reports_to_id' => ['sometimes', 'nullable', 'integer', $sameCompany('positions')],
            'mission' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'responsibilities' => ['sometimes', 'array', 'max:50'],
            'responsibilities.*' => ['string', 'max:500'],
            // Vrai pour le poste de responsable de l'unité.
            'is_head' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(LifecycleStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
    }
}
