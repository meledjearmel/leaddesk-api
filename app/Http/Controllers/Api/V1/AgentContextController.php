<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditKind;
use App\Models\Agent;
use App\Models\Rule;
use App\Models\Task;
use App\Models\Unit;
use App\Support\Audit;
use App\Support\ToolPermissions;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Contexte d'un agent pour une tâche (RM-33) : son poste, et tout ce qui est
 * lié à l'activité confiée. Chaque lecture est journalisée (RM-34).
 */
#[Group('Contexte des agents', weight: 19)]
class AgentContextController extends ApiController
{
    /**
     * Lire le contexte d'un agent pour une tâche.
     *
     * Réservé aux agents affectés à la tâche (et au CEO). Contient le poste
     * de l'agent, l'objectif de l'activité, ses champs, les tâches liées et
     * qui fait quoi, les personnes impliquées, qui contacter (responsables des
     * équipes), les règles applicables et les permissions d'exécution.
     *
     * @return array{data: array<string, mixed>}
     */
    public function show(Request $request, int $agent, int $task, Audit $audit, ToolPermissions $permissions): array
    {
        $company = $this->company($request);
        $agentModel = $company->agents()->with('position.unit')->findOrFail($agent);
        $taskModel = $company->tasks()->with(['activity.units', 'activity.type', 'activity.tasks.agents', 'activity.tasks.step'])->findOrFail($task);

        if (! $taskModel->agents()->whereKey($agentModel->id)->exists()) {
            throw new AccessDeniedHttpException("Cet agent n'est pas affecté à cette tâche.");
        }

        $activity = $taskModel->activity;
        $people = collect();

        foreach ($activity->tasks as $sibling) {
            foreach ($sibling->agents as $member) {
                $people->put($member->id, ['agent_id' => $member->id, 'name' => $member->name]);
            }
        }

        $contacts = $activity->units->map(fn (Unit $unit): array => [
            'unit_id' => $unit->id,
            'unit' => $unit->name,
            'responsible' => ($head = $unit->responsibleAgent()) === null
                ? null
                : ['agent_id' => $head->id, 'name' => $head->name],
        ])->values()->all();

        $rules = $company->rules()->with('unit')->orderBy('id')->get()->filter(
            fn (Rule $rule): bool => ($rule->unit === null || $activity->units->contains(fn (Unit $unit): bool => $rule->appliesToUnit($unit)))
                && ($rule->extended_to_running || $activity->created_at === null || $rule->effective_at->lessThanOrEqualTo($activity->created_at)),
        );

        $audit->record($company, 'agent.context_viewed', $taskModel, ['agent_id' => $agentModel->id], AuditKind::Consultation);

        return ['data' => [
            'agent' => [
                'id' => $agentModel->id,
                'name' => $agentModel->name,
                'position' => $agentModel->position === null ? null : [
                    'id' => $agentModel->position->id,
                    'title' => $agentModel->position->title,
                    'mission' => $agentModel->position->mission,
                    'responsibilities' => $agentModel->position->responsibilities ?? [],
                    'unit' => $agentModel->position->unit->name,
                ],
            ],
            'task' => [
                'id' => $taskModel->id,
                'title' => $taskModel->title,
                'description' => $taskModel->description,
            ],
            'activity' => [
                'id' => $activity->id,
                'title' => $activity->title,
                'objective' => $activity->description,
                'type' => $activity->type->name,
                'field_values' => (object) ($activity->field_values ?? []),
                'due_at' => $activity->due_at?->toIso8601ZuluString(),
            ],
            'related_tasks' => $activity->tasks->map(fn (Task $sibling): array => [
                'id' => $sibling->id,
                'title' => $sibling->title,
                'step_category' => $sibling->step?->category->value,
                'agents' => $sibling->agents->map(fn (Agent $member): string => $member->name)->values()->all(),
            ])->values()->all(),
            'people' => $people->values()->all(),
            'contacts' => $contacts,
            'rules' => $rules->map(fn (Rule $rule): array => [
                'id' => $rule->id,
                'title' => $rule->title,
                'body' => $rule->body,
            ])->values()->all(),
            'tool_permissions' => $permissions->resolve($agentModel->position),
        ]];
    }
}
