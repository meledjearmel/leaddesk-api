<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\ProjectResource;
use App\Models\Project;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Projets : regroupements facultatifs d'activités (RM-17).
 */
#[Group('Projets', weight: 10)]
class ProjectController extends ApiController
{
    /**
     * Lister les projets.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ProjectResource::collection(
            $this->company($request)->projects()->withCount('activities')->orderBy('id')->get(),
        );
    }

    /**
     * Créer un projet.
     */
    public function store(Request $request): JsonResponse
    {
        $project = $this->company($request)->projects()->create($request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]));

        return (new ProjectResource($project->refresh()->loadCount('activities')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Lire un projet.
     */
    public function show(Request $request, int $project): ProjectResource
    {
        return new ProjectResource($this->find($request, $project)->loadCount('activities'));
    }

    /**
     * Modifier un projet.
     */
    public function update(Request $request, int $project): ProjectResource
    {
        $model = $this->find($request, $project);
        $model->update($request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]));

        return new ProjectResource($model->loadCount('activities'));
    }

    /**
     * Mettre un projet à la corbeille.
     *
     * Ses activités ne sont pas supprimées : elles restent rattachées au
     * projet, qui peut être restauré.
     */
    public function destroy(Request $request, int $project): Response
    {
        $this->find($request, $project)->delete();

        return response()->noContent();
    }

    /**
     * Restaurer un projet depuis la corbeille.
     */
    public function restore(Request $request, int $project): ProjectResource
    {
        $model = $this->company($request)->projects()->onlyTrashed()->findOrFail($project);
        $model->restore();

        return new ProjectResource($model->loadCount('activities'));
    }

    private function find(Request $request, int $id): Project
    {
        return $this->company($request)->projects()->findOrFail($id);
    }
}
