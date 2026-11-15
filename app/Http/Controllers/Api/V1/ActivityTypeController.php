<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Work\RealignWorkflowSteps;
use App\Enums\FieldType;
use App\Enums\WorkflowScope;
use App\Http\Resources\V1\ActivityTypeResource;
use App\Models\ActivityType;
use App\Models\Company;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Types d'activité : modèles de travail créés sans code, avec leurs champs
 * propres et une base de tâches proposée (RM-20).
 */
#[Group("Types d'activité", weight: 9)]
class ActivityTypeController extends ApiController
{
    /**
     * Lister les types d'activité.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ActivityTypeResource::collection(
            $this->company($request)->activityTypes()->orderBy('id')->get(),
        );
    }

    /**
     * Créer un type d'activité.
     */
    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $type = $company->activityTypes()->create($this->validated($request, $company));

        return (new ActivityTypeResource($type->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Lire un type d'activité.
     */
    public function show(Request $request, int $activityType): ActivityTypeResource
    {
        return new ActivityTypeResource($this->find($request, $activityType));
    }

    /**
     * Modifier un type d'activité.
     *
     * Les changements s'appliquent aussi aux activités en cours (RM-21) :
     * elles lisent toujours les champs et les workflows actuels de leur type.
     */
    public function update(Request $request, int $activityType, RealignWorkflowSteps $realign): ActivityTypeResource
    {
        $company = $this->company($request);
        $type = $this->find($request, $activityType);
        $type->update($this->validated($request, $company, partial: true));

        // RM-21 : si le parcours du type a changé, les activités et les tâches
        // en cours sont replacées sur une étape du nouveau parcours.
        if ($type->wasChanged(['activity_workflow_id', 'task_workflow_id'])) {
            $realign->forType($type);
        }

        return new ActivityTypeResource($type);
    }

    /**
     * Mettre un type d'activité à la corbeille.
     */
    public function destroy(Request $request, int $activityType): Response
    {
        $this->find($request, $activityType)->delete();

        return response()->noContent();
    }

    private function find(Request $request, int $id): ActivityType
    {
        return $this->company($request)->activityTypes()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Company $company, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Unité responsable par défaut, de la même entreprise.
            'unit_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('units', 'id')->where('company_id', $company->id)->whereNull('deleted_at'),
            ],
            // Workflow de portée « activity ».
            'activity_workflow_id' => ['sometimes', 'nullable', 'integer', $this->workflowRule($company, WorkflowScope::Activity)],
            // Workflow de portée « task ».
            'task_workflow_id' => ['sometimes', 'nullable', 'integer', $this->workflowRule($company, WorkflowScope::Task)],
            'fields' => ['sometimes', 'array', 'max:60'],
            // Identifiant technique du champ, unique dans le type.
            'fields.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:120'],
            'fields.*.type' => ['required', Rule::enum(FieldType::class)],
            'fields.*.required' => ['sometimes', 'boolean'],
            // Options proposées ; obligatoires pour un champ de type « select ».
            'fields.*.options' => ['required_if:fields.*.type,select', 'array', 'max:60'],
            'fields.*.options.*' => ['string', 'max:120', 'distinct'],
            'task_templates' => ['sometimes', 'array', 'max:60'],
            'task_templates.*.title' => ['required', 'string', 'max:160'],
            'task_templates.*.description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        if (isset($data['fields'])) {
            /** @var list<array{key: string, label: string, type: string, required?: bool, options?: array<int|string, string>}> $fields */
            $fields = $data['fields'];
            $data['fields'] = array_map(fn (array $field): array => [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'required' => (bool) ($field['required'] ?? false),
                'options' => array_values($field['options'] ?? []),
            ], $fields);
        }

        if (isset($data['task_templates'])) {
            /** @var list<array{title: string, description?: string|null}> $templates */
            $templates = $data['task_templates'];
            $data['task_templates'] = array_map(fn (array $template): array => [
                'title' => $template['title'],
                'description' => $template['description'] ?? null,
            ], $templates);
        }

        return $data;
    }

    private function workflowRule(Company $company, WorkflowScope $scope): Exists
    {
        return Rule::exists('workflows', 'id')
            ->where('company_id', $company->id)
            ->where('scope', $scope->value)
            ->whereNull('deleted_at');
    }
}
