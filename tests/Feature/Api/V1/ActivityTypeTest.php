<?php

namespace Tests\Feature\Api\V1;

use App\Models\ActivityType;
use App\Models\Company;
use App\Models\Unit;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityTypeTest extends TestCase
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
     * RM-03 : les types d'activité et leurs champs se créent sans code.
     * RM-20 : le type propose une base de tâches.
     */
    public function test_rm20_a_type_is_created_with_its_own_fields_and_a_task_base(): void
    {
        $unit = Unit::factory()->for($this->company)->create();
        $activityWorkflow = Workflow::factory()->for($this->company)->withSteps()->create();
        $taskWorkflow = Workflow::factory()->for($this->company)->forTasks()->withSteps()->create();

        $this->postJson('/api/v1/activity-types', [
            'name' => 'Inspection HSE',
            'description' => 'Contrôle d\'un site.',
            'unit_id' => $unit->id,
            'activity_workflow_id' => $activityWorkflow->id,
            'task_workflow_id' => $taskWorkflow->id,
            'fields' => [
                ['key' => 'site', 'label' => 'Site', 'type' => 'text', 'required' => true],
                ['key' => 'niveau', 'label' => 'Niveau de risque', 'type' => 'select', 'options' => ['Faible', 'Moyen', 'Élevé']],
                ['key' => 'date_visite', 'label' => 'Date de visite', 'type' => 'date'],
            ],
            'task_templates' => [
                ['title' => 'Préparer la liste de contrôle'],
                ['title' => 'Rédiger les constats', 'description' => 'Un constat par écart.'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.unit_id', $unit->id)
            ->assertJsonPath('data.activity_workflow_id', $activityWorkflow->id)
            ->assertJsonPath('data.task_workflow_id', $taskWorkflow->id)
            ->assertJsonPath('data.fields.0', ['key' => 'site', 'label' => 'Site', 'type' => 'text', 'required' => true, 'options' => []])
            ->assertJsonPath('data.fields.1.options', ['Faible', 'Moyen', 'Élevé'])
            ->assertJsonPath('data.fields.2.required', false)
            ->assertJsonPath('data.task_templates.0', ['title' => 'Préparer la liste de contrôle', 'description' => null])
            ->assertJsonPath('data.task_templates.1.description', 'Un constat par écart.');
    }

    public function test_a_minimal_type_needs_only_a_name(): void
    {
        $this->postJson('/api/v1/activity-types', ['name' => 'Divers'])
            ->assertCreated()
            ->assertJsonPath('data.fields', [])
            ->assertJsonPath('data.task_templates', [])
            ->assertJsonPath('data.activity_workflow_id', null);
    }

    public function test_field_definitions_are_validated(): void
    {
        $this->postJson('/api/v1/activity-types', [
            'name' => 'Type',
            'fields' => [
                ['key' => 'Site Nom', 'label' => '', 'type' => 'couleur'],
                ['key' => 'choix', 'label' => 'Choix', 'type' => 'select'],
                ['key' => 'doublon', 'label' => 'A', 'type' => 'text'],
                ['key' => 'doublon', 'label' => 'B', 'type' => 'text'],
            ],
            'task_templates' => [['title' => '']],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'fields.0.key', 'fields.0.label', 'fields.0.type', 'fields.1.options',
            'fields.2.key', 'task_templates.0.title',
        ]);
    }

    /**
     * RM-18 : le parcours des activités et celui des tâches ne s'échangent pas.
     */
    public function test_rm18_each_workflow_slot_only_accepts_its_own_scope(): void
    {
        $activityWorkflow = Workflow::factory()->for($this->company)->create();
        $taskWorkflow = Workflow::factory()->for($this->company)->forTasks()->create();
        $foreign = Workflow::factory()->create();

        $this->postJson('/api/v1/activity-types', [
            'name' => 'Type',
            'activity_workflow_id' => $taskWorkflow->id,
            'task_workflow_id' => $activityWorkflow->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['activity_workflow_id', 'task_workflow_id']);

        $this->postJson('/api/v1/activity-types', ['name' => 'Type', 'activity_workflow_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('activity_workflow_id');
    }

    public function test_a_type_is_edited_and_trashed(): void
    {
        $type = ActivityType::factory()->for($this->company)->create();

        $this->patchJson("/api/v1/activity-types/{$type->id}", [
            'name' => 'Développement',
            'fields' => [['key' => 'depot', 'label' => 'Dépôt', 'type' => 'text']],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Développement')
            ->assertJsonPath('data.fields.0.key', 'depot');

        $this->getJson('/api/v1/activity-types')->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/activity-types/{$type->id}")->assertNoContent();
        $this->assertSoftDeleted($type);
        $this->getJson('/api/v1/activity-types')->assertJsonCount(0, 'data');
    }

    public function test_a_type_of_another_company_is_unreachable(): void
    {
        $foreign = ActivityType::factory()->create();
        $foreignUnit = Unit::factory()->create();

        $this->getJson("/api/v1/activity-types/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/v1/activity-types/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/activity-types/{$foreign->id}")->assertNotFound();
        $this->postJson('/api/v1/activity-types', ['name' => 'Type', 'unit_id' => $foreignUnit->id])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_id');
    }
}
