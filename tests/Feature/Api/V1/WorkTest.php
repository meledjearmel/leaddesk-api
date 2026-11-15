<?php

namespace Tests\Feature\Api\V1;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Position;
use App\Models\Project;
use App\Models\Task;
use App\Models\Unit;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 3 : projets, activités et tâches.
 */
class WorkTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Unit $team;

    private ActivityType $type;

    private Workflow $activityWorkflow;

    private Workflow $taskWorkflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->team = Unit::factory()->for($this->company)->create(['name' => 'Développement']);
        $this->activityWorkflow = Workflow::factory()->for($this->company)->withSteps()->create();
        $this->taskWorkflow = Workflow::factory()->for($this->company)->forTasks()->withSteps()->create();
        $this->type = ActivityType::factory()->for($this->company)->create([
            'unit_id' => $this->team->id,
            'activity_workflow_id' => $this->activityWorkflow->id,
            'task_workflow_id' => $this->taskWorkflow->id,
            'fields' => [
                ['key' => 'depot', 'label' => 'Dépôt', 'type' => 'text', 'required' => true, 'options' => []],
                ['key' => 'priorite', 'label' => 'Priorité', 'type' => 'select', 'required' => false, 'options' => ['Basse', 'Haute']],
            ],
            'task_templates' => [
                ['title' => 'Analyser le besoin', 'description' => null],
                ['title' => 'Développer', 'description' => 'Avec ses tests.'],
            ],
        ]);

        Sanctum::actingAs($this->company->user);
    }

    private function step(Workflow $workflow, string $category): int
    {
        return $workflow->steps()->where('category', $category)->firstOrFail()->id;
    }

    /**
     * Agent en service, responsable ou simple membre de l'unité.
     */
    private function agentIn(Unit $unit, bool $head, bool $active = true): Agent
    {
        $position = Position::factory()->in($unit)->create(['is_head' => $head]);

        return Agent::factory()->for($this->company)->create([
            'position_id' => $position->id,
            'status' => $active ? 'active' : 'draft',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function as(Agent $agent): array
    {
        return ['X-LeadDesk-Agent' => (string) $agent->id];
    }

    private function activity(): Activity
    {
        $activity = Activity::factory()->ofType($this->type)->create();
        $activity->units()->sync([$this->team->id]);

        return $activity;
    }

    /**
     * RM-17 : une activité peut exister sans projet, mobiliser plusieurs
     * équipes, et un projet peut regrouper des activités.
     */
    public function test_rm17_an_activity_lives_with_or_without_a_project_and_spans_teams(): void
    {
        $design = Unit::factory()->for($this->company)->create();
        $project = Project::factory()->for($this->company)->create();

        $alone = $this->postJson('/api/v1/activities', [
            'activity_type_id' => $this->type->id,
            'title' => 'Corriger la page de contact',
            'field_values' => ['depot' => 'site-vitrine'],
        ])->assertCreated()
            ->assertJsonPath('data.project_id', null)
            ->assertJsonPath('data.unit_ids', [$this->team->id])
            ->assertJsonPath('data.step_category', 'todo')
            ->assertJsonPath('data.field_values.depot', 'site-vitrine')
            ->assertJsonPath('data.tasks', [])
            ->json('data.id');

        $grouped = $this->postJson('/api/v1/activities', [
            'activity_type_id' => $this->type->id,
            'title' => 'Refonte du catalogue',
            'project_id' => $project->id,
            'unit_ids' => [$this->team->id, $design->id],
            'field_values' => ['depot' => 'catalogue', 'priorite' => 'Haute'],
            'due_at' => '2026-12-01T09:00:00Z',
        ])->assertCreated()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.unit_ids', [$this->team->id, $design->id])
            ->json('data.id');

        $this->getJson('/api/v1/activities')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/activities?without_project=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alone);
        $this->getJson("/api/v1/activities?project_id={$project->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $grouped);
        $this->getJson("/api/v1/activities?unit_id={$design->id}")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/projects/{$project->id}")->assertJsonPath('data.activities_count', 1);

        // Sortir l'activité de son projet ne la supprime pas.
        $this->patchJson("/api/v1/activities/{$grouped}", ['project_id' => null])
            ->assertOk()->assertJsonPath('data.project_id', null);
    }

    public function test_field_values_follow_the_definition_of_the_type(): void
    {
        $payload = ['activity_type_id' => $this->type->id, 'title' => 'Activité'];

        $this->postJson('/api/v1/activities', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('field_values.depot');

        $this->postJson('/api/v1/activities', [...$payload, 'field_values' => ['depot' => 'x', 'priorite' => 'Urgente']])
            ->assertUnprocessable()->assertJsonValidationErrors('field_values.priorite');

        // Une valeur que le type ne définit pas n'est pas conservée.
        $this->postJson('/api/v1/activities', [...$payload, 'field_values' => ['depot' => 'x', 'inconnu' => 'y']])
            ->assertCreated()
            ->assertJsonPath('data.field_values', ['depot' => 'x']);
    }

    /**
     * RM-20 : le type propose une base de tâches, que le responsable reprend,
     * adapte ou remplace.
     */
    public function test_rm20_the_task_base_is_taken_adapted_or_replaced(): void
    {
        $id = $this->postJson('/api/v1/activities', [
            'activity_type_id' => $this->type->id,
            'title' => 'Nouvelle fonctionnalité',
            'field_values' => ['depot' => 'app'],
            'with_task_base' => true,
        ])->assertCreated()
            ->assertJsonCount(2, 'data.tasks')
            ->assertJsonPath('data.tasks.0.title', 'Analyser le besoin')
            ->assertJsonPath('data.tasks.0.unit_id', $this->team->id)
            ->assertJsonPath('data.tasks.0.step_category', 'todo')
            ->assertJsonPath('data.tasks.1.description', 'Avec ses tests.')
            ->json('data.id');

        [$first, $second] = Task::query()->where('activity_id', $id)->orderBy('id')->pluck('id')->all();

        // Adapter, remplacer, compléter.
        $this->patchJson("/api/v1/tasks/{$first}", ['title' => 'Cadrer avec Produit'])->assertOk();
        $this->deleteJson("/api/v1/tasks/{$second}")->assertNoContent();
        $this->postJson("/api/v1/activities/{$id}/tasks", ['title' => 'Écrire la documentation'])
            ->assertCreated()->assertJsonPath('data.unit_id', $this->team->id);

        $this->getJson("/api/v1/activities/{$id}")
            ->assertJsonCount(2, 'data.tasks')
            ->assertJsonPath('data.tasks.0.title', 'Cadrer avec Produit')
            ->assertJsonPath('data.tasks.1.title', 'Écrire la documentation');

        // Le type lui-même n'a pas bougé.
        $this->assertCount(2, $this->type->fresh()->task_templates ?? []);

        // Sans la base : aucune tâche.
        $this->postJson('/api/v1/activities', [
            'activity_type_id' => $this->type->id,
            'title' => 'Autre',
            'field_values' => ['depot' => 'app'],
        ])->assertCreated()->assertJsonPath('data.tasks', []);
    }

    public function test_a_task_belongs_to_one_of_the_teams_of_its_activity(): void
    {
        $design = Unit::factory()->for($this->company)->create();
        $elsewhere = Unit::factory()->for($this->company)->create();
        $activity = $this->activity();
        $activity->units()->sync([$this->team->id, $design->id]);

        $this->postJson("/api/v1/activities/{$activity->id}/tasks", ['title' => 'Sans équipe'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_id');

        $this->postJson("/api/v1/activities/{$activity->id}/tasks", ['title' => 'Ailleurs', 'unit_id' => $elsewhere->id])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_id');

        $id = $this->postJson("/api/v1/activities/{$activity->id}/tasks", ['title' => 'Maquette', 'unit_id' => $design->id])
            ->assertCreated()->json('data.id');

        $this->getJson("/api/v1/tasks?unit_id={$design->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/tasks?activity_id={$activity->id}")->assertJsonCount(1, 'data');
    }

    /**
     * RM-18 : l'activité et ses tâches avancent chacune dans leur workflow.
     */
    public function test_rm18_an_activity_and_its_tasks_move_through_their_own_workflows(): void
    {
        $activity = $this->activity();
        $task = Task::factory()->in($activity, $this->team)->create();

        $this->postJson("/api/v1/activities/{$activity->id}/step", [
            'workflow_step_id' => $this->step($this->activityWorkflow, 'in_progress'),
        ])->assertOk()->assertJsonPath('data.step_category', 'in_progress');

        // Une étape du workflow de tâche n'est pas une étape d'activité, et inversement.
        $this->postJson("/api/v1/activities/{$activity->id}/step", [
            'workflow_step_id' => $this->step($this->taskWorkflow, 'in_progress'),
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow_step_id');

        $this->postJson("/api/v1/tasks/{$task->id}/step", [
            'workflow_step_id' => $this->step($this->activityWorkflow, 'in_progress'),
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow_step_id');

        $this->postJson("/api/v1/tasks/{$task->id}/step", [
            'workflow_step_id' => $this->step($this->taskWorkflow, 'review'),
        ])->assertOk()->assertJsonPath('data.step_category', 'review');

        // L'activité, elle, peut être déclarée terminée directement.
        $this->postJson("/api/v1/activities/{$activity->id}/step", [
            'workflow_step_id' => $this->step($this->activityWorkflow, 'done'),
        ])->assertOk()->assertJsonPath('data.step_category', 'done');
    }

    /**
     * RM-19 : le responsable d'équipe confirme la fin des tâches de son équipe.
     */
    public function test_rm19_only_the_team_head_confirms_the_end_of_a_task(): void
    {
        $head = $this->agentIn($this->team, head: true);
        $member = $this->agentIn($this->team, head: false);
        $outsider = $this->agentIn(Unit::factory()->for($this->company)->create(), head: true);
        $task = Task::factory()->in($this->activity(), $this->team)->create();
        $done = $this->step($this->taskWorkflow, 'done');

        // Personne ne passe une tâche à « terminé » par un simple changement d'étape.
        $this->postJson("/api/v1/tasks/{$task->id}/step", ['workflow_step_id' => $done])->assertConflict();
        $this->postJson("/api/v1/tasks/{$task->id}/step", ['workflow_step_id' => $done], $this->as($head))->assertConflict();

        // Ni un membre, ni le responsable d'une autre équipe, ni le CEO à la place du responsable.
        $this->postJson("/api/v1/tasks/{$task->id}/confirm-completion", [], $this->as($member))->assertForbidden();
        $this->postJson("/api/v1/tasks/{$task->id}/confirm-completion", [], $this->as($outsider))->assertForbidden();
        $this->postJson("/api/v1/tasks/{$task->id}/confirm-completion")->assertForbidden();
        $this->assertNull($task->fresh()?->completed_at);

        $this->postJson("/api/v1/tasks/{$task->id}/confirm-completion", [], $this->as($head))
            ->assertOk()
            ->assertJsonPath('data.step_category', 'done')
            ->assertJsonPath('data.confirmed_by_agent_id', $head->id)
            ->assertJsonPath('data.confirmed_by_ceo', false);

        $this->assertNotNull($task->fresh()?->completed_at);
        $this->postJson("/api/v1/tasks/{$task->id}/confirm-completion", [], $this->as($head))->assertConflict();

        $entry = AuditEntry::query()->where('event', 'task.completion_confirmed')->sole();
        $this->assertSame('agent', $entry->actor_type->value);
        $this->assertSame($head->id, $entry->actor_id);
    }

    /**
     * RM-19 : sans responsable dans l'équipe, c'est celui de l'unité parente.
     */
    public function test_rm19_the_head_of_the_parent_unit_confirms_when_the_team_has_none(): void
    {
        $department = Unit::factory()->for($this->company)->create();
        $this->team->update(['parent_id' => $department->id]);
        $departmentHead = $this->agentIn($department, head: true);
        $this->agentIn($this->team, head: true, active: false);
        $task = Task::factory()->in($this->activity(), $this->team)->create();

        $this->postJson("/api/v1/tasks/{$task->id}/confirm-completion")->assertForbidden();

        $this->postJson("/api/v1/tasks/{$task->id}/confirm-completion", [], $this->as($departmentHead))
            ->assertOk()->assertJsonPath('data.confirmed_by_agent_id', $departmentHead->id);
    }

    /**
     * RM-19 : faute de tout responsable en service, le CEO confirme lui-même.
     */
    public function test_rm19_the_ceo_confirms_only_when_no_head_is_in_service(): void
    {
        $member = $this->agentIn($this->team, head: false);
        $task = Task::factory()->in($this->activity(), $this->team)->create();

        $this->postJson("/api/v1/tasks/{$task->id}/confirm-completion", [], $this->as($member))->assertForbidden();

        $this->postJson("/api/v1/tasks/{$task->id}/confirm-completion")
            ->assertOk()
            ->assertJsonPath('data.confirmed_by_ceo', true)
            ->assertJsonPath('data.confirmed_by_agent_id', null);
    }

    public function test_reopening_a_confirmed_task_clears_its_confirmation(): void
    {
        $task = Task::factory()->in($this->activity(), $this->team)->create();
        $this->postJson("/api/v1/tasks/{$task->id}/confirm-completion")->assertOk();

        $this->postJson("/api/v1/tasks/{$task->id}/step", [
            'workflow_step_id' => $this->step($this->taskWorkflow, 'in_progress'),
        ])->assertOk()
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.confirmed_by_ceo', false);
    }

    /**
     * RM-08 : les responsables affectent les agents aux tâches de leur équipe.
     */
    public function test_rm08_the_team_head_assigns_agents_to_the_tasks_of_the_team(): void
    {
        $head = $this->agentIn($this->team, head: true);
        $lead = $this->agentIn($this->team, head: false);
        $helper = $this->agentIn($this->team, head: false);
        $outsider = $this->agentIn(Unit::factory()->for($this->company)->create(), head: true);
        $task = Task::factory()->in($this->activity(), $this->team)->create();

        $payload = ['agents' => [
            ['agent_id' => $helper->id],
            ['agent_id' => $lead->id, 'is_lead' => true],
        ]];

        $this->putJson("/api/v1/tasks/{$task->id}/agents", $payload, $this->as($lead))->assertForbidden();
        $this->putJson("/api/v1/tasks/{$task->id}/agents", $payload, $this->as($outsider))->assertForbidden();

        $this->putJson("/api/v1/tasks/{$task->id}/agents", $payload, $this->as($head))
            ->assertOk()
            ->assertJsonCount(2, 'data.agents')
            ->assertJsonPath('data.agents.0.agent_id', $lead->id)
            ->assertJsonPath('data.agents.0.is_lead', true)
            ->assertJsonPath('data.agents.1.agent_id', $helper->id);

        $this->getJson("/api/v1/tasks?agent_id={$helper->id}")->assertJsonCount(1, 'data');

        // Le CEO peut toujours le faire ; plusieurs agents ensemble, sans principal.
        $this->putJson("/api/v1/tasks/{$task->id}/agents", ['agents' => [
            ['agent_id' => $lead->id],
            ['agent_id' => $helper->id],
        ]])->assertOk()->assertJsonPath('data.agents.0.is_lead', false);

        $this->putJson("/api/v1/tasks/{$task->id}/agents", ['agents' => [
            ['agent_id' => $lead->id, 'is_lead' => true],
            ['agent_id' => $helper->id, 'is_lead' => true],
        ]])->assertUnprocessable()->assertJsonValidationErrors('agents');

        $this->putJson("/api/v1/tasks/{$task->id}/agents", ['agents' => [
            ['agent_id' => Agent::factory()->create()->id],
        ]])->assertUnprocessable();
    }

    /**
     * RM-21 : une modification de type d'activité s'applique aussi aux
     * activités en cours.
     */
    public function test_rm21_a_change_of_type_applies_to_activities_in_progress(): void
    {
        $activity = $this->activity();
        $activity->update(['field_values' => ['depot' => 'app']]);

        // Nouveau champ obligatoire : il vaut tout de suite pour l'activité en cours.
        $this->patchJson("/api/v1/activity-types/{$this->type->id}", ['fields' => [
            ['key' => 'depot', 'label' => 'Dépôt', 'type' => 'text', 'required' => true],
            ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => true],
        ]])->assertOk();

        $this->patchJson("/api/v1/activities/{$activity->id}", ['field_values' => ['depot' => 'app']])
            ->assertUnprocessable()->assertJsonValidationErrors('field_values.client');

        $this->patchJson("/api/v1/activities/{$activity->id}", ['field_values' => ['depot' => 'app', 'client' => 'Mairie']])
            ->assertOk()->assertJsonPath('data.field_values.client', 'Mairie');
    }

    /**
     * RM-21 : changer le workflow du type replace les activités et les tâches
     * en cours sur le nouveau parcours, en gardant leur avancement.
     */
    public function test_rm21_changing_the_workflow_of_a_type_moves_work_in_progress(): void
    {
        $activity = $this->activity();
        $activity->update(['workflow_step_id' => $this->step($this->activityWorkflow, 'in_progress')]);
        $task = Task::factory()->in($activity, $this->team)->create([
            'workflow_step_id' => $this->step($this->taskWorkflow, 'review'),
        ]);

        $newActivityWorkflow = Workflow::factory()->for($this->company)->withSteps()->create();
        $newTaskWorkflow = Workflow::factory()->for($this->company)->forTasks()->create();
        $onlyStep = $newTaskWorkflow->steps()->create(['name' => 'Ouverte', 'category' => 'todo', 'sort_order' => 0]);

        $this->patchJson("/api/v1/activity-types/{$this->type->id}", [
            'activity_workflow_id' => $newActivityWorkflow->id,
            'task_workflow_id' => $newTaskWorkflow->id,
        ])->assertOk();

        // Même catégorie dans le nouveau parcours ; sinon l'étape de départ.
        $this->assertSame($this->step($newActivityWorkflow, 'in_progress'), $activity->fresh()?->workflow_step_id);
        $this->assertSame($onlyStep->id, $task->fresh()?->workflow_step_id);
    }

    /**
     * RM-21 : retirer une étape d'un workflow replace ce qui s'y trouvait.
     */
    public function test_rm21_removing_a_step_moves_the_work_that_was_on_it(): void
    {
        $task = Task::factory()->in($this->activity(), $this->team)->create([
            'workflow_step_id' => $this->step($this->taskWorkflow, 'review'),
        ]);

        $kept = $this->taskWorkflow->steps()->where('category', '!=', 'review')->get();

        $this->patchJson("/api/v1/workflows/{$this->taskWorkflow->id}", [
            'steps' => $kept->map(fn ($step) => [
                'id' => $step->id,
                'name' => $step->name,
                'category' => $step->category->value,
            ])->all(),
        ])->assertOk();

        $this->assertSame($this->step($this->taskWorkflow, 'todo'), $task->fresh()?->workflow_step_id);
    }

    public function test_an_agent_out_of_service_or_foreign_cannot_act(): void
    {
        $draft = $this->agentIn($this->team, head: true, active: false);
        $foreign = Agent::factory()->active()->create();

        $this->getJson('/api/v1/tasks', $this->as($draft))->assertForbidden();
        $this->getJson('/api/v1/tasks', $this->as($foreign))->assertForbidden();
        $this->getJson('/api/v1/tasks', ['X-LeadDesk-Agent' => 'abc'])->assertForbidden();
    }

    /**
     * RM-34 : ce qu'un agent fait est journalisé à son nom.
     */
    public function test_rm34_what_an_agent_does_is_logged_under_its_name(): void
    {
        $head = $this->agentIn($this->team, head: true);
        $activity = $this->activity();

        $this->postJson("/api/v1/activities/{$activity->id}/tasks", ['title' => 'Relire'], $this->as($head))
            ->assertCreated();

        $entry = AuditEntry::query()->where('event', 'task.created')->sole();
        $this->assertSame('agent', $entry->actor_type->value);
        $this->assertSame($head->id, $entry->actor_id);
    }

    public function test_projects_are_managed_and_restored(): void
    {
        $id = $this->postJson('/api/v1/projects', ['name' => 'Site vitrine', 'description' => 'Refonte.'])
            ->assertCreated()->assertJsonPath('data.activities_count', 0)->json('data.id');

        $this->patchJson("/api/v1/projects/{$id}", ['name' => 'Site institutionnel'])
            ->assertOk()->assertJsonPath('data.name', 'Site institutionnel');
        $this->postJson('/api/v1/projects', ['name' => ''])->assertUnprocessable();

        $this->deleteJson("/api/v1/projects/{$id}")->assertNoContent();
        $this->getJson('/api/v1/projects')->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/projects/{$id}/restore")->assertOk();
        $this->getJson('/api/v1/projects')->assertJsonCount(1, 'data');
    }

    public function test_activities_and_tasks_go_to_the_trash_and_activities_come_back(): void
    {
        $activity = $this->activity();
        Task::factory()->in($activity, $this->team)->create();

        $this->deleteJson("/api/v1/activities/{$activity->id}")->assertNoContent();
        $this->assertSoftDeleted($activity);
        $this->getJson('/api/v1/activities')->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/activities/{$activity->id}/restore")->assertOk();
        $this->getJson("/api/v1/activities/{$activity->id}")->assertJsonCount(1, 'data.tasks');
    }

    public function test_work_of_another_company_is_unreachable(): void
    {
        $foreignActivity = Activity::factory()->create();
        $foreignTask = Task::factory()->create();
        $foreignProject = Project::factory()->create();
        $foreignType = ActivityType::factory()->create();

        $this->getJson("/api/v1/activities/{$foreignActivity->id}")->assertNotFound();
        $this->patchJson("/api/v1/activities/{$foreignActivity->id}", ['title' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/activities/{$foreignActivity->id}/tasks", ['title' => 'x'])->assertNotFound();
        $this->getJson("/api/v1/tasks/{$foreignTask->id}")->assertNotFound();
        $this->postJson("/api/v1/tasks/{$foreignTask->id}/confirm-completion")->assertNotFound();
        $this->putJson("/api/v1/tasks/{$foreignTask->id}/agents", ['agents' => []])->assertNotFound();
        $this->getJson("/api/v1/projects/{$foreignProject->id}")->assertNotFound();

        $this->postJson('/api/v1/activities', ['activity_type_id' => $foreignType->id, 'title' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('activity_type_id');
        $this->postJson('/api/v1/activities', [
            'activity_type_id' => $this->type->id,
            'title' => 'x',
            'project_id' => $foreignProject->id,
            'unit_ids' => [$foreignTask->unit_id],
            'field_values' => ['depot' => 'x'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['project_id', 'unit_ids.0']);

        $this->getJson('/api/v1/activities')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tasks')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/projects')->assertJsonCount(0, 'data');
    }
}
