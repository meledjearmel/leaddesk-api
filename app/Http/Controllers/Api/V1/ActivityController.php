<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\ActivityResource;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Company;
use App\Support\FieldValues;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Activités : le travail concret, créé depuis un type, avec ou sans projet,
 * pour une ou plusieurs équipes (RM-17).
 */
#[Group('Activités', weight: 11)]
class ActivityController extends ApiController
{
    public function __construct(private readonly FieldValues $fieldValues) {}

    /**
     * Lister les activités.
     *
     * Filtrable par projet (`project_id`, ou `without_project=1` pour les
     * activités sans projet), par type et par équipe.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'project_id' => ['sometimes', 'integer'],
            'without_project' => ['sometimes', 'boolean'],
            'activity_type_id' => ['sometimes', 'integer'],
            'unit_id' => ['sometimes', 'integer'],
        ]);

        $query = $this->company($request)->activities()->with(['units', 'step']);

        if (isset($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if ($request->boolean('without_project')) {
            $query->whereNull('project_id');
        }

        if (isset($filters['activity_type_id'])) {
            $query->where('activity_type_id', $filters['activity_type_id']);
        }

        if (isset($filters['unit_id'])) {
            $query->whereHas('units', fn ($units) => $units->whereKey($filters['unit_id']));
        }

        return ActivityResource::collection($query->orderBy('id')->get());
    }

    /**
     * Créer une activité.
     *
     * L'activité démarre à la première étape du workflow de son type. Avec
     * `with_task_base`, la base de tâches du type est reprise telle quelle ;
     * le responsable peut ensuite l'adapter ou la remplacer (RM-20).
     */
    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'activity_type_id' => [
                'required', 'integer',
                Rule::exists('activity_types', 'id')->where('company_id', $company->id)->whereNull('deleted_at'),
            ],
            ...$this->commonRules($company, partial: false),
            // Reprendre la base de tâches proposée par le type.
            'with_task_base' => ['sometimes', 'boolean'],
        ]);

        $type = $company->activityTypes()->whereKey($data['activity_type_id'])->firstOrFail();
        /** @var array<string, mixed> $values */
        $values = $data['field_values'] ?? [];
        /** @var list<int> $unitIds */
        $unitIds = $data['unit_ids'] ?? ($type->unit_id === null ? [] : [$type->unit_id]);

        if ($request->boolean('with_task_base') && $unitIds === []) {
            throw ValidationException::withMessages([
                'unit_ids' => ['Indiquez au moins une équipe pour reprendre la base de tâches.'],
            ]);
        }

        $activity = DB::transaction(function () use ($company, $type, $data, $values, $unitIds, $request): Activity {
            $activity = $company->activities()->create([
                'activity_type_id' => $type->id,
                'project_id' => $data['project_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'workflow_step_id' => $type->activityWorkflow?->initialStep()?->id,
                'field_values' => $this->fieldValues->validate($type, $values),
                'due_at' => $data['due_at'] ?? null,
            ]);

            $activity->units()->sync($unitIds);

            if ($request->boolean('with_task_base')) {
                $this->createTaskBase($activity, $type, $unitIds[0]);
            }

            return $activity;
        });

        return (new ActivityResource($activity->refresh()->load(['units', 'step', 'tasks.agents', 'tasks.step'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Lire une activité et ses tâches.
     */
    public function show(Request $request, int $activity): ActivityResource
    {
        return new ActivityResource(
            $this->find($request, $activity)->load(['units', 'step', 'tasks.agents', 'tasks.step']),
        );
    }

    /**
     * Modifier une activité.
     *
     * Les valeurs de champs sont validées avec la définition actuelle du
     * type (RM-21).
     */
    public function update(Request $request, int $activity): ActivityResource
    {
        $company = $this->company($request);
        $model = $this->find($request, $activity);
        $data = $request->validate($this->commonRules($company, partial: true));

        DB::transaction(function () use ($model, $data): void {
            $attributes = array_intersect_key($data, array_flip(['project_id', 'title', 'description', 'due_at']));

            if (array_key_exists('field_values', $data)) {
                /** @var array<string, mixed> $values */
                $values = $data['field_values'];
                $attributes['field_values'] = $this->fieldValues->validate($model->type, $values);
            }

            $model->update($attributes);

            if (isset($data['unit_ids'])) {
                $model->units()->sync($data['unit_ids']);
            }
        });

        return new ActivityResource($model->refresh()->load(['units', 'step']));
    }

    /**
     * Faire avancer une activité.
     *
     * L'étape doit appartenir au workflow d'activité actuel du type.
     */
    public function move(Request $request, int $activity): ActivityResource
    {
        $model = $this->find($request, $activity);
        $data = $request->validate(['workflow_step_id' => ['required', 'integer']]);

        $step = $model->type->activityWorkflow?->steps()->whereKey($data['workflow_step_id'])->first();

        if ($step === null) {
            throw ValidationException::withMessages([
                'workflow_step_id' => ["Cette étape n'appartient pas au workflow de ce type d'activité."],
            ]);
        }

        $model->update(['workflow_step_id' => $step->id]);

        return new ActivityResource($model->refresh()->load(['units', 'step']));
    }

    /**
     * Mettre une activité à la corbeille.
     */
    public function destroy(Request $request, int $activity): Response
    {
        $this->find($request, $activity)->delete();

        return response()->noContent();
    }

    /**
     * Restaurer une activité depuis la corbeille.
     */
    public function restore(Request $request, int $activity): ActivityResource
    {
        $model = $this->company($request)->activities()->onlyTrashed()->findOrFail($activity);
        $model->restore();

        return new ActivityResource($model->load(['units', 'step']));
    }

    private function find(Request $request, int $id): Activity
    {
        return $this->company($request)->activities()->findOrFail($id);
    }

    private function createTaskBase(Activity $activity, ActivityType $type, int $unitId): void
    {
        $initialStep = $type->taskWorkflow?->initialStep()?->id;

        foreach ($type->task_templates ?? [] as $template) {
            $activity->tasks()->create([
                'company_id' => $activity->company_id,
                'unit_id' => $unitId,
                'title' => $template['title'],
                'description' => $template['description'],
                'workflow_step_id' => $initialStep,
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function commonRules(Company $company, bool $partial): array
    {
        $sameCompany = fn (string $table): Exists => Rule::exists($table, 'id')
            ->where('company_id', $company->id)
            ->whereNull('deleted_at');

        return [
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            // Projet de rattachement ; facultatif (RM-17).
            'project_id' => ['sometimes', 'nullable', 'integer', $sameCompany('projects')],
            // Équipes mobilisées ; plusieurs possibles (RM-17).
            'unit_ids' => ['sometimes', 'array', 'max:50'],
            'unit_ids.*' => ['integer', 'distinct', $sameCompany('units')],
            // Valeurs des champs propres au type, par clé.
            'field_values' => ['sometimes', 'array'],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
