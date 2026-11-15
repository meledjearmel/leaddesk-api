<?php

namespace Tests\Feature\Api\V1;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Position;
use App\Models\Rule;
use App\Models\Task;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentContextTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RM-33 : l'agent voit son poste et tout ce qui est lié à l'activité
     * confiée. RM-34 : la consultation est journalisée à son nom.
     */
    public function test_rm33_rm34_an_agent_sees_its_post_and_everything_linked_to_its_activity(): void
    {
        $company = Company::factory()->create();
        $team = Unit::factory()->for($company)->create(['name' => 'Développement']);
        $other = Unit::factory()->for($company)->create(['name' => 'Ailleurs']);
        $headPosition = Position::factory()->in($team)->create(['is_head' => true]);
        $devPosition = Position::factory()->in($team)->create(['title' => 'Développeur', 'mission' => 'Coder proprement.']);
        $head = Agent::factory()->for($company)->active()->create(['name' => 'Yanis', 'position_id' => $headPosition->id]);
        $dev = Agent::factory()->for($company)->active()->create(['name' => 'Lina', 'position_id' => $devPosition->id]);
        $outsider = Agent::factory()->for($company)->active()->create(['name' => 'Hors tâche']);

        $activity = Activity::factory()->ofType(ActivityType::factory()->for($company)->create(['name' => 'Fonctionnalité']))
            ->create(['title' => 'Page contact', 'description' => 'Permettre aux clients d\'écrire.']);
        $activity->units()->sync([$team->id]);
        $task = Task::factory()->in($activity, $team)->create(['title' => 'Formulaire']);
        $sibling = Task::factory()->in($activity, $team)->create(['title' => 'Envoi des e-mails']);
        $task->agents()->attach($dev->id);
        $sibling->agents()->attach($head->id);

        Rule::factory()->for($company)->create(['title' => 'Tests avant fusion', 'unit_id' => $team->id, 'effective_at' => now()->subDay()]);
        Rule::factory()->for($company)->create(['title' => 'Règle d\'ailleurs', 'unit_id' => $other->id, 'effective_at' => now()->subDay()]);

        Sanctum::actingAs($company->user);
        $url = "/api/v1/agents/{$dev->id}/tasks/{$task->id}/context";

        $this->getJson($url, ['X-LeadDesk-Agent' => (string) $dev->id])
            ->assertOk()
            ->assertJsonPath('data.agent.position.title', 'Développeur')
            ->assertJsonPath('data.agent.position.mission', 'Coder proprement.')
            ->assertJsonPath('data.activity.objective', 'Permettre aux clients d\'écrire.')
            ->assertJsonCount(2, 'data.related_tasks')
            ->assertJsonPath('data.related_tasks.1.agents', ['Yanis'])
            ->assertJsonCount(2, 'data.people')
            ->assertJsonPath('data.contacts.0.responsible.name', 'Yanis')
            ->assertJsonCount(1, 'data.rules')
            ->assertJsonPath('data.rules.0.title', 'Tests avant fusion')
            ->assertJsonPath('data.tool_permissions.read_file', 'ask');

        $entry = AuditEntry::query()->where('event', 'agent.context_viewed')->sole();
        $this->assertSame('consultation', $entry->kind->value);
        $this->assertSame('agent', $entry->actor_type->value);
        $this->assertSame($dev->id, $entry->actor_id);

        // Un agent non affecté n'y a pas accès.
        $this->getJson("/api/v1/agents/{$outsider->id}/tasks/{$task->id}/context")->assertForbidden();
        $this->getJson('/api/v1/agents/'.Agent::factory()->create()->id."/tasks/{$task->id}/context")->assertNotFound();
    }
}
