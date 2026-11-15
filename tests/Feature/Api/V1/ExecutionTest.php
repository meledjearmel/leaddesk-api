<?php

namespace Tests\Feature\Api\V1;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Decision;
use App\Models\Device;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Unit;
use App\Models\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4, côté serveur : ordinateurs hôtes, exécutions, coûts réels et
 * limites de coût.
 */
class ExecutionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Project $project;

    private Task $task;

    private Agent $agent;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $unit = Unit::factory()->for($this->company)->create();
        $this->project = Project::factory()->for($this->company)->create();
        $activity = Activity::factory()
            ->ofType(ActivityType::factory()->for($this->company)->create())
            ->create(['project_id' => $this->project->id]);
        $activity->units()->sync([$unit->id]);
        $this->task = Task::factory()->in($activity, $unit)->create(['title' => 'Écrire le rapport']);
        $this->agent = Agent::factory()->for($this->company)->active()->create();
        $this->task->agents()->attach($this->agent->id);
        $this->device = Device::factory()->for($this->company)->create(['name' => 'PC bureau']);

        Sanctum::actingAs($this->company->user);
    }

    private function startRun(): int
    {
        return $this->postJson("/api/v1/tasks/{$this->task->id}/runs", [
            'agent_id' => $this->agent->id,
            'device_id' => $this->device->id,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function usage(int $run, int $costMicros, array $overrides = []): TestResponse
    {
        return $this->postJson("/api/v1/task-runs/{$run}/usage", [
            'provider' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'input_tokens' => 1000,
            'output_tokens' => 200,
            'cost_micros' => $costMicros,
            ...$overrides,
        ]);
    }

    public function test_the_application_registers_its_computer_once(): void
    {
        $payload = ['installation_id' => '0b0e7c1e-6c1f-4b7e-9d1a-3f5f1f1d2a10', 'name' => 'PC portable', 'platform' => 'windows'];

        $id = $this->postJson('/api/v1/devices', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'PC portable')
            ->assertJsonPath('data.online', true)
            ->json('data.id');

        // Même installation : même ordinateur, renommé.
        $this->postJson('/api/v1/devices', [...$payload, 'name' => 'PC portable d\'Armel'])
            ->assertOk()->assertJsonPath('data.id', $id);

        $this->getJson('/api/v1/devices')->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/devices', ['installation_id' => 'pas-un-uuid', 'name' => '', 'platform' => 'amiga'])
            ->assertUnprocessable()->assertJsonValidationErrors(['installation_id', 'name', 'platform']);
    }

    public function test_a_computer_without_sign_of_life_is_shown_offline(): void
    {
        $this->travel(10)->minutes();

        $this->getJson('/api/v1/devices')->assertJsonPath('data.0.online', false);

        $this->postJson("/api/v1/devices/{$this->device->id}/heartbeat")
            ->assertOk()->assertJsonPath('data.online', true);

        // Le délai est un réglage, pas une constante.
        config(['leaddesk.device_online_seconds' => 5]);
        $this->travel(10)->seconds();
        $this->getJson('/api/v1/devices')->assertJsonPath('data.0.online', false);
    }

    public function test_a_heartbeat_does_not_fill_the_audit_log(): void
    {
        $before = AuditEntry::query()->count();

        $this->postJson("/api/v1/devices/{$this->device->id}/heartbeat")->assertOk();

        $this->assertSame($before, AuditEntry::query()->count());
    }

    public function test_a_run_records_its_agent_and_its_host_computer(): void
    {
        $id = $this->startRun();

        $this->getJson("/api/v1/task-runs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.agent_id', $this->agent->id)
            ->assertJsonPath('data.device_id', $this->device->id)
            ->assertJsonPath('data.device_name', 'PC bureau')
            ->assertJsonPath('data.host_online', true)
            ->assertJsonPath('data.waiting_for_host', false)
            ->assertJsonPath('data.cost_micros', 0);

        $this->getJson('/api/v1/task-runs?open=1')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/task-runs?device_id={$this->device->id}")->assertJsonCount(1, 'data');
    }

    public function test_a_run_needs_an_assigned_agent_in_service_and_is_unique_per_task(): void
    {
        $unassigned = Agent::factory()->for($this->company)->active()->create();
        $draft = Agent::factory()->for($this->company)->create();
        $this->task->agents()->attach($draft->id);
        $foreignDevice = Device::factory()->create();

        $this->postJson("/api/v1/tasks/{$this->task->id}/runs", ['agent_id' => $unassigned->id, 'device_id' => $this->device->id])
            ->assertUnprocessable()->assertJsonValidationErrors('agent_id');
        $this->postJson("/api/v1/tasks/{$this->task->id}/runs", ['agent_id' => $draft->id, 'device_id' => $this->device->id])
            ->assertUnprocessable()->assertJsonValidationErrors('agent_id');
        $this->postJson("/api/v1/tasks/{$this->task->id}/runs", ['agent_id' => $this->agent->id, 'device_id' => $foreignDevice->id])
            ->assertUnprocessable()->assertJsonValidationErrors('device_id');

        $this->startRun();

        $this->postJson("/api/v1/tasks/{$this->task->id}/runs", ['agent_id' => $this->agent->id, 'device_id' => $this->device->id])
            ->assertConflict();
        $this->deleteJson("/api/v1/devices/{$this->device->id}")->assertConflict();
    }

    /**
     * Application fermée : la tâche attend son hôte et reprend à la réouverture.
     */
    public function test_a_run_waits_when_its_host_goes_offline_and_resumes_when_it_returns(): void
    {
        $id = $this->startRun();

        $this->travel(10)->minutes();

        $this->getJson("/api/v1/task-runs/{$id}")
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.host_online', false)
            ->assertJsonPath('data.waiting_for_host', true);

        $this->postJson("/api/v1/devices/{$this->device->id}/heartbeat")->assertOk();

        $this->getJson("/api/v1/task-runs/{$id}")->assertJsonPath('data.waiting_for_host', false);
    }

    /**
     * RM-35 : le coût réel est rattaché à l'agent, à la tâche, à l'activité et
     * au projet.
     */
    public function test_rm35_a_real_cost_is_attached_to_the_work(): void
    {
        $id = $this->startRun();

        $this->usage($id, 12_500)->assertOk()->assertJsonPath('data.cost_micros', 12_500);
        $this->usage($id, 7_500, ['model' => 'claude-haiku-5-5'])->assertOk()->assertJsonPath('data.cost_micros', 20_000);

        $record = UsageRecord::query()->orderBy('id')->firstOrFail();
        $this->assertSame($this->agent->id, $record->agent_id);
        $this->assertSame($this->task->id, $record->task_id);
        $this->assertSame($this->task->activity_id, $record->activity_id);
        $this->assertSame($this->project->id, $record->project_id);
        $this->assertSame('claude-opus-5-5', $record->model);
        $this->assertSame(1000, $record->input_tokens);

        foreach (['agent' => $this->agent->id, 'task' => $this->task->id, 'activity' => $this->task->activity_id, 'project' => $this->project->id] as $group => $key) {
            $this->getJson("/api/v1/costs/summary?group_by={$group}")
                ->assertOk()
                ->assertJsonPath('data.real_micros', 20_000)
                ->assertJsonPath('data.groups.0.id', $key)
                ->assertJsonPath('data.groups.0.real_micros', 20_000)
                ->assertJsonPath('data.groups.0.calls', 2);
        }

        $this->getJson('/api/v1/costs/summary?group_by=model')
            ->assertJsonCount(2, 'data.groups')
            ->assertJsonPath('data.groups.0.id', 'claude-haiku-5-5')
            ->assertJsonPath('data.groups.0.real_micros', 7_500);
    }

    /**
     * RM-35 : les estimations restent distinctes des coûts réels.
     */
    public function test_rm35_estimates_are_never_mixed_with_real_costs(): void
    {
        $this->patchJson("/api/v1/tasks/{$this->task->id}", ['estimated_cost_micros' => 500_000])
            ->assertOk()->assertJsonPath('data.estimated_cost_micros', 500_000);

        $this->getJson('/api/v1/costs/summary')
            ->assertJsonPath('data.estimated_micros', 500_000)
            ->assertJsonPath('data.real_micros', 0);

        $this->usage($this->startRun(), 30_000)->assertOk();

        $this->getJson('/api/v1/costs/summary')
            ->assertJsonPath('data.estimated_micros', 500_000)
            ->assertJsonPath('data.real_micros', 30_000);
    }

    /**
     * RM-35 : un appel dont le tarif est inconnu reste « non chiffré ».
     */
    public function test_rm35_an_unpriced_call_is_counted_but_never_given_an_invented_cost(): void
    {
        $id = $this->startRun();

        $this->usage($id, 10_000)->assertOk();
        $this->postJson("/api/v1/task-runs/{$id}/usage", ['provider' => 'maison', 'model' => 'inconnu', 'input_tokens' => 50])
            ->assertOk()->assertJsonPath('data.cost_micros', 10_000);

        $this->getJson('/api/v1/costs/summary?group_by=task')
            ->assertJsonPath('data.real_micros', 10_000)
            ->assertJsonPath('data.unpriced_calls', 1)
            ->assertJsonPath('data.groups.0.calls', 2)
            ->assertJsonPath('data.groups.0.unpriced_calls', 1);
    }

    /**
     * RM-36 : quand une tâche atteint sa limite de coût, elle se met en pause
     * et attend la décision du CEO pour reprendre.
     */
    public function test_rm36_reaching_the_limit_pauses_the_run_and_waits_for_the_ceo(): void
    {
        $this->putJson('/api/v1/cost-limits', ['scope' => 'task', 'scope_id' => $this->task->id, 'limit_micros' => 50_000])
            ->assertOk();
        $id = $this->startRun();

        $this->usage($id, 30_000)->assertOk()->assertJsonPath('data.status', 'running');
        $this->assertSame(0, Decision::query()->count());

        $this->usage($id, 25_000)
            ->assertOk()
            ->assertJsonPath('data.status', 'paused_cost')
            ->assertJsonPath('data.cost_micros', 55_000);

        $decision = Decision::query()->sole();
        $this->assertSame('cost_limit', $decision->kind);
        $this->assertSame('pending', $decision->status->value);
        $this->assertSame('Limite de coût atteinte : Écrire le rapport', $decision->title);
        $this->assertSame(
            ['task_run_id' => $id, 'scope' => 'task', 'scope_id' => $this->task->id, 'limit_micros' => 50_000, 'spent_micros' => 55_000],
            $decision->payload,
        );
        $this->assertDatabaseHas('audit_entries', ['event' => 'task_run.paused_for_cost', 'subject_id' => $id]);

        // Personne ne relance l'exécution sans la décision du CEO.
        $this->postJson("/api/v1/task-runs/{$id}/resume")->assertConflict();
        $this->postJson("/api/v1/task-runs/{$id}/resume", [], ['X-LeadDesk-Agent' => (string) $this->agent->id])->assertConflict();
        $this->assertSame('paused_cost', TaskRun::query()->findOrFail($id)->status->value);

        // Un coût qui arrive encore est enregistré, sans seconde décision.
        $this->usage($id, 1_000)->assertOk()->assertJsonPath('data.status', 'paused_cost');
        $this->assertSame(1, Decision::query()->count());

        // Le CEO relève la limite : l'exécution reprend.
        $this->postJson("/api/v1/decisions/{$decision->id}/approve", ['new_limit_micros' => 40_000])
            ->assertUnprocessable()->assertJsonValidationErrors('new_limit_micros');
        $this->postJson("/api/v1/decisions/{$decision->id}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors('new_limit_micros');
        $this->assertSame('paused_cost', TaskRun::query()->findOrFail($id)->status->value);

        $this->postJson("/api/v1/decisions/{$decision->id}/approve", ['new_limit_micros' => 200_000])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->getJson("/api/v1/task-runs/{$id}")->assertJsonPath('data.status', 'running');
        $this->getJson('/api/v1/cost-limits')->assertJsonPath('data.0.limit_micros', 200_000);
        $this->usage($id, 10_000)->assertOk()->assertJsonPath('data.status', 'running');
    }

    /**
     * RM-36 : si le CEO refuse de dépenser plus, l'exécution s'arrête.
     */
    public function test_rm36_refusing_to_spend_more_stops_the_run(): void
    {
        $this->putJson('/api/v1/cost-limits', ['scope' => 'task', 'scope_id' => $this->task->id, 'limit_micros' => 10_000]);
        $id = $this->startRun();
        $this->usage($id, 10_000)->assertJsonPath('data.status', 'paused_cost');

        $this->postJson('/api/v1/decisions/'.Decision::query()->sole()->id.'/reject', ['note' => 'Trop cher.'])
            ->assertOk();

        $this->getJson("/api/v1/task-runs/{$id}")->assertJsonPath('data.status', 'cancelled');
        $this->assertNotNull(TaskRun::query()->findOrFail($id)->ended_at);
        $this->usage($id, 1)->assertConflict();
    }

    /**
     * RM-36 / RM-37 : la limite peut aussi porter sur le projet ou sur toute
     * l'entreprise ; aucune valeur n'est codée en dur.
     */
    public function test_rm36_project_and_company_limits_also_pause_the_run(): void
    {
        // Sans limite réglée, rien ne met en pause.
        $first = $this->startRun();
        $this->usage($first, 900_000_000)->assertJsonPath('data.status', 'running');
        $this->postJson("/api/v1/task-runs/{$first}/finish", ['outcome' => 'completed'])->assertOk();

        $this->putJson('/api/v1/cost-limits', ['scope' => 'project', 'scope_id' => $this->project->id, 'limit_micros' => 900_000_001])
            ->assertOk();
        $second = $this->startRun();
        $this->usage($second, 1)->assertJsonPath('data.status', 'paused_cost');
        $this->assertSame('project', Decision::query()->sole()->payload['scope'] ?? null);
        $this->postJson("/api/v1/task-runs/{$second}/finish", ['outcome' => 'cancelled'])->assertOk();

        // Limite de projet retirée, limite d'entreprise posée.
        $this->putJson('/api/v1/cost-limits', ['scope' => 'project', 'scope_id' => $this->project->id, 'limit_micros' => null])
            ->assertOk();
        $this->putJson('/api/v1/cost-limits', ['scope' => 'company', 'limit_micros' => 1_000])->assertOk();
        $third = $this->startRun();
        $this->usage($third, 0)->assertJsonPath('data.status', 'paused_cost');
        $this->assertSame('company', Decision::query()->latest('id')->firstOrFail()->payload['scope'] ?? null);
    }

    public function test_only_the_ceo_sets_cost_limits(): void
    {
        $this->putJson(
            '/api/v1/cost-limits',
            ['scope' => 'company', 'limit_micros' => 1],
            ['X-LeadDesk-Agent' => (string) $this->agent->id],
        )->assertForbidden();

        $this->putJson('/api/v1/cost-limits', ['scope' => 'task', 'limit_micros' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('scope_id');
        $this->putJson('/api/v1/cost-limits', ['scope' => 'task', 'scope_id' => Task::factory()->create()->id, 'limit_micros' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('scope_id');
        $this->putJson('/api/v1/cost-limits', ['scope' => 'planete', 'limit_micros' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['scope', 'limit_micros']);

        $this->getJson('/api/v1/cost-limits')->assertJsonCount(0, 'data');
    }

    public function test_the_ceo_pauses_resumes_and_the_host_finishes_a_run(): void
    {
        $id = $this->startRun();

        $this->postJson("/api/v1/task-runs/{$id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson("/api/v1/task-runs/{$id}/resume")->assertOk()->assertJsonPath('data.status', 'running');
        $this->postJson("/api/v1/task-runs/{$id}/finish", ['outcome' => 'autre'])->assertUnprocessable();

        $this->postJson("/api/v1/task-runs/{$id}/finish", ['outcome' => 'failed', 'reason' => 'Fichier introuvable.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.status_reason', 'Fichier introuvable.');

        $this->postJson("/api/v1/task-runs/{$id}/pause")->assertConflict();

        // Finir l'exécution ne termine pas la tâche (RM-19) : une nouvelle peut commencer.
        $this->assertNull($this->task->fresh()?->completed_at);
        $this->startRun();
    }

    public function test_execution_data_of_another_company_is_unreachable(): void
    {
        $foreignDevice = Device::factory()->create();
        $foreignTask = Task::factory()->create();

        $this->postJson("/api/v1/devices/{$foreignDevice->id}/heartbeat")->assertNotFound();
        $this->patchJson("/api/v1/devices/{$foreignDevice->id}", ['name' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/tasks/{$foreignTask->id}/runs", ['agent_id' => $this->agent->id, 'device_id' => $this->device->id])
            ->assertNotFound();

        $otherCompany = Company::factory()->create();
        $id = $this->startRun();
        Sanctum::actingAs($otherCompany->user);

        $this->getJson("/api/v1/task-runs/{$id}")->assertNotFound();
        $this->postJson("/api/v1/task-runs/{$id}/usage", ['cost_micros' => 1])->assertNotFound();
        $this->postJson("/api/v1/task-runs/{$id}/finish", ['outcome' => 'cancelled'])->assertNotFound();
        $this->getJson('/api/v1/task-runs')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/costs/summary')->assertJsonPath('data.real_micros', 0);
    }
}
