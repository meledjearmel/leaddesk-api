<?php

namespace Tests\Feature\Api\V1;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Agent;
use App\Models\Company;
use App\Models\Decision;
use App\Models\Device;
use App\Models\Position;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4 : permissions d'exécution par poste et dossiers autorisés.
 */
class ExecutionPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Position $position;

    private Agent $agent;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $unit = Unit::factory()->for($this->company)->create();
        $this->position = Position::factory()->in($unit)->create();
        $this->agent = Agent::factory()->for($this->company)->active()->create(['position_id' => $this->position->id]);
        $this->device = Device::factory()->for($this->company)->create();

        Sanctum::actingAs($this->company->user);
    }

    /**
     * @return array<string, string>
     */
    private function asAgent(): array
    {
        return ['X-LeadDesk-Agent' => (string) $this->agent->id];
    }

    private function openRun(): TaskRun
    {
        $unit = $this->position->unit;
        $activity = Activity::factory()->ofType(ActivityType::factory()->for($this->company)->create())->create();
        $task = Task::factory()->in($activity, $unit)->create();

        return TaskRun::query()->create([
            'company_id' => $this->company->id,
            'task_id' => $task->id,
            'agent_id' => $this->agent->id,
            'device_id' => $this->device->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function test_the_tool_catalog_and_its_cautious_default_are_settings(): void
    {
        $this->getJson('/api/v1/execution/tools')
            ->assertOk()
            ->assertJsonPath('data.default_mode', 'ask')
            ->assertJsonFragment(['tool' => 'read_file', 'label' => 'Lire un fichier']);

        config(['leaddesk.default_tool_mode' => 'deny']);
        $this->getJson('/api/v1/execution/tools')->assertJsonPath('data.default_mode', 'deny');
    }

    /**
     * Les permissions sont celles du poste, héritées par l'agent qui l'occupe.
     */
    public function test_an_agent_inherits_the_tool_permissions_of_its_position(): void
    {
        $this->getJson("/api/v1/agents/{$this->agent->id}/execution-profile")
            ->assertOk()
            ->assertJsonPath('data.tool_permissions.read_file', 'ask')
            ->assertJsonPath('data.tool_permissions.run_command', 'ask');

        $this->putJson("/api/v1/positions/{$this->position->id}/tool-permissions", ['permissions' => [
            'read_file' => 'allow',
            'run_command' => 'deny',
        ]])->assertOk()
            ->assertJsonPath('data.tool_permissions.read_file', 'allow')
            ->assertJsonPath('data.tool_permissions.write_file', 'ask');

        $this->getJson("/api/v1/agents/{$this->agent->id}/execution-profile")
            ->assertJsonPath('data.tool_permissions.read_file', 'allow')
            ->assertJsonPath('data.tool_permissions.run_command', 'deny');

        // Sans poste, l'agent ne peut rien faire sur l'ordinateur hôte.
        $this->agent->update(['position_id' => null]);
        $this->getJson("/api/v1/agents/{$this->agent->id}/execution-profile")
            ->assertJsonPath('data.tool_permissions.read_file', 'deny');
    }

    public function test_permissions_are_validated_and_set_by_the_ceo_only(): void
    {
        $url = "/api/v1/positions/{$this->position->id}/tool-permissions";

        $this->putJson($url, ['permissions' => ['read_file' => 'toujours']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions.read_file');
        $this->putJson($url, ['permissions' => ['formater_le_disque' => 'allow']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');

        // Un agent ne s'accorde pas d'outils à lui-même.
        $this->putJson($url, ['permissions' => ['run_command' => 'allow']], $this->asAgent())->assertForbidden();
        $this->assertNull($this->position->fresh()?->tool_permissions);
    }

    public function test_an_action_is_allowed_denied_or_submitted_for_approval(): void
    {
        $this->putJson("/api/v1/positions/{$this->position->id}/tool-permissions", ['permissions' => [
            'read_file' => 'allow',
            'run_command' => 'deny',
            'write_file' => 'ask',
        ]]);
        $run = $this->openRun();
        $url = "/api/v1/task-runs/{$run->id}/approvals";

        $this->postJson($url, ['tool' => 'read_file', 'summary' => 'Lire brief.md'], $this->asAgent())
            ->assertOk()->assertJsonPath('data.outcome', 'allowed')->assertJsonPath('data.decision', null);

        $this->postJson($url, ['tool' => 'run_command', 'summary' => 'npm install'], $this->asAgent())
            ->assertOk()->assertJsonPath('data.outcome', 'denied');

        $this->postJson($url, ['tool' => 'outil_inconnu', 'summary' => 'x'], $this->asAgent())
            ->assertOk()->assertJsonPath('data.outcome', 'denied');

        $decision = $this->postJson($url, ['tool' => 'write_file', 'summary' => 'Écrire rapport.md'], $this->asAgent())
            ->assertAccepted()
            ->assertJsonPath('data.outcome', 'pending')
            ->assertJsonPath('data.decision.kind', 'tool_approval')
            ->assertJsonPath('data.decision.payload.summary', 'Écrire rapport.md')
            ->assertJsonPath('data.decision.requested_by_agent_id', $this->agent->id)
            ->json('data.decision.id');

        // L'agent ne s'approuve pas lui-même ; le CEO décide.
        $this->postJson("/api/v1/decisions/{$decision}/approve", [], $this->asAgent())->assertForbidden();
        $this->postJson("/api/v1/decisions/{$decision}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, Decision::query()->count());
    }

    public function test_a_finished_run_cannot_ask_for_anything(): void
    {
        $run = $this->openRun();
        $run->update(['status' => 'completed', 'ended_at' => now()]);

        $this->postJson("/api/v1/task-runs/{$run->id}/approvals", ['tool' => 'read_file', 'summary' => 'x'])
            ->assertConflict();
    }

    /**
     * Une seule autorisation couvre les deux dossiers ; changer un chemin
     * vaut nouvelle autorisation. Le serveur ne garde que les chemins.
     */
    public function test_the_ceo_authorizes_the_folders_of_a_project_per_computer(): void
    {
        $project = Project::factory()->for($this->company)->create();
        $url = "/api/v1/projects/{$project->id}/folders";

        $this->putJson($url, [
            'device_id' => $this->device->id,
            'documentation_path' => 'C:\\Projets\\site\\docs',
            'code_path' => 'C:\\Projets\\site\\src',
        ])->assertNoContent();

        $first = $this->getJson($url)->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame(['code', 'documentation'], array_column($first, 'kind'));

        $this->travel(1)->hours();

        $this->putJson($url, [
            'device_id' => $this->device->id,
            'documentation_path' => 'C:\\Projets\\site\\docs',
            'code_path' => 'D:\\depots\\site',
        ])->assertNoContent();

        $second = $this->getJson($url)->json('data');
        $this->assertSame('D:\\depots\\site', $second[0]['path']);
        $this->assertNotSame($first[0]['authorized_at'], $second[0]['authorized_at']);
        $this->assertSame($first[1]['authorized_at'], $second[1]['authorized_at']);

        $this->putJson($url, ['device_id' => $this->device->id, 'documentation_path' => null, 'code_path' => 'D:\\depots\\site'])
            ->assertNoContent();
        $this->getJson("{$url}?device_id={$this->device->id}")->assertJsonCount(1, 'data');
    }

    public function test_only_the_ceo_authorizes_folders_and_only_on_its_own_computers(): void
    {
        $project = Project::factory()->for($this->company)->create();
        $url = "/api/v1/projects/{$project->id}/folders";
        $payload = ['device_id' => $this->device->id, 'documentation_path' => 'C:\\docs', 'code_path' => null];

        $this->putJson($url, $payload, $this->asAgent())->assertForbidden();
        $this->putJson($url, [...$payload, 'device_id' => Device::factory()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('device_id');
        $this->putJson('/api/v1/projects/'.Project::factory()->create()->id.'/folders', $payload)->assertNotFound();

        $this->getJson($url)->assertJsonCount(0, 'data');
    }
}
