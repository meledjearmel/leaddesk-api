<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Sanctum::actingAs($this->company->user);
    }

    /**
     * RM-18 : activités et tâches suivent des workflows distincts.
     * RM-03 : libellés, nombre et ordre des étapes sont configurables.
     */
    public function test_rm18_activity_and_task_workflows_are_distinct_and_configurable(): void
    {
        $this->postJson('/api/v1/workflows', [
            'name' => 'Parcours projet',
            'scope' => 'activity',
            'steps' => [
                ['name' => 'Cadrage', 'category' => 'todo'],
                ['name' => 'Exécution', 'category' => 'in_progress'],
                ['name' => 'Livré', 'category' => 'done'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.scope', 'activity')
            ->assertJsonCount(3, 'data.steps')
            ->assertJsonPath('data.steps.0.name', 'Cadrage')
            ->assertJsonPath('data.steps.2.category', 'done');

        $this->postJson('/api/v1/workflows', [
            'name' => 'Parcours de tâche',
            'scope' => 'task',
            'steps' => [
                ['name' => 'À faire', 'category' => 'todo'],
                ['name' => 'En cours', 'category' => 'in_progress'],
                ['name' => 'À confirmer', 'category' => 'review'],
                ['name' => 'Confirmée', 'category' => 'done'],
                ['name' => 'Abandonnée', 'category' => 'cancelled'],
            ],
        ])->assertCreated()->assertJsonCount(5, 'data.steps');

        $this->getJson('/api/v1/workflows')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/workflows?scope=task')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Parcours de tâche');
        $this->getJson('/api/v1/workflows?scope=projet')->assertUnprocessable();
    }

    public function test_creation_validates_its_input(): void
    {
        $this->postJson('/api/v1/workflows', ['name' => '', 'scope' => 'autre', 'steps' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'scope', 'steps']);

        $this->postJson('/api/v1/workflows', [
            'name' => 'Parcours',
            'scope' => 'task',
            'steps' => [['name' => '', 'category' => 'fini']],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.name', 'steps.0.category']);
    }

    public function test_steps_are_renamed_reordered_added_and_removed_while_keeping_their_identity(): void
    {
        $workflow = Workflow::factory()->for($this->company)->forTasks()->withSteps()->create();
        [$todo, $doing, $review, $done] = $workflow->steps()->pluck('id')->all();

        $this->patchJson("/api/v1/workflows/{$workflow->id}", [
            'name' => 'Parcours court',
            'steps' => [
                ['id' => $doing, 'name' => 'En réalisation', 'category' => 'in_progress'],
                ['id' => $todo, 'name' => 'À faire', 'category' => 'todo'],
                ['name' => 'Bloquée', 'category' => 'in_progress'],
                ['id' => $done, 'name' => 'Terminé', 'category' => 'done'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Parcours court')
            ->assertJsonCount(4, 'data.steps')
            ->assertJsonPath('data.steps.0.id', $doing)
            ->assertJsonPath('data.steps.0.name', 'En réalisation')
            ->assertJsonPath('data.steps.1.id', $todo)
            ->assertJsonPath('data.steps.2.name', 'Bloquée')
            ->assertJsonPath('data.steps.3.id', $done);

        $this->assertSoftDeleted('workflow_steps', ['id' => $review]);
        $this->assertDatabaseHas('audit_entries', ['event' => 'workflow.steps_changed', 'subject_id' => $workflow->id]);
    }

    public function test_a_step_of_another_workflow_cannot_be_adopted(): void
    {
        $workflow = Workflow::factory()->for($this->company)->withSteps()->create();
        $other = Workflow::factory()->for($this->company)->withSteps()->create();
        $foreignStep = $other->steps()->firstOrFail()->id;

        $this->patchJson("/api/v1/workflows/{$workflow->id}", [
            'steps' => [['id' => $foreignStep, 'name' => 'Volée', 'category' => 'todo']],
        ])->assertUnprocessable()->assertJsonValidationErrors('steps.0.id');

        $this->assertSame(4, $workflow->steps()->count());
        $this->assertSame(4, $other->steps()->count());
    }

    public function test_the_scope_of_a_workflow_never_changes(): void
    {
        $workflow = Workflow::factory()->for($this->company)->withSteps()->create();

        $this->patchJson("/api/v1/workflows/{$workflow->id}", ['scope' => 'task', 'name' => 'Renommé'])
            ->assertOk()
            ->assertJsonPath('data.scope', 'activity')
            ->assertJsonPath('data.name', 'Renommé');
    }

    public function test_a_workflow_is_trashed(): void
    {
        $workflow = Workflow::factory()->for($this->company)->withSteps()->create();

        $this->deleteJson("/api/v1/workflows/{$workflow->id}")->assertNoContent();

        $this->assertSoftDeleted($workflow);
        $this->getJson('/api/v1/workflows')->assertJsonCount(0, 'data');
    }

    public function test_a_workflow_of_another_company_is_unreachable(): void
    {
        $foreign = Workflow::factory()->withSteps()->create();

        $this->getJson("/api/v1/workflows/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/v1/workflows/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/workflows/{$foreign->id}")->assertNotFound();
        $this->getJson('/api/v1/workflows')->assertJsonCount(0, 'data');
    }
}
