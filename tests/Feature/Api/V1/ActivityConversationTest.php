<?php

namespace Tests\Feature\Api\V1;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Device;
use App\Models\Position;
use App\Models\Task;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityConversationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Activity $activity;

    private Task $task;

    private Agent $head;

    private Agent $dev;

    private Agent $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $team = Unit::factory()->for($this->company)->create();
        $this->head = Agent::factory()->for($this->company)->active()->create([
            'position_id' => Position::factory()->in($team)->create(['is_head' => true])->id,
        ]);
        $this->dev = Agent::factory()->for($this->company)->active()->create([
            'position_id' => Position::factory()->in($team)->create()->id,
        ]);
        $this->outsider = Agent::factory()->for($this->company)->active()->create();

        $this->activity = Activity::factory()->ofType(ActivityType::factory()->for($this->company)->create())->create();
        $this->activity->units()->sync([$team->id]);
        $this->task = Task::factory()->in($this->activity, $team)->create();
        $this->task->agents()->attach($this->dev->id);

        Sanctum::actingAs($this->company->user);
    }

    /**
     * @return array<string, string>
     */
    private function as(Agent $agent): array
    {
        return ['X-LeadDesk-Agent' => (string) $agent->id];
    }

    private function url(string $path = 'messages'): string
    {
        return "/api/v1/activities/{$this->activity->id}/{$path}";
    }

    /**
     * RM-34 : chaque message est tracé au nom de son auteur.
     */
    public function test_rm34_participants_and_the_ceo_talk_in_the_activity(): void
    {
        $this->postJson($this->url(), ['body' => 'Où en est le formulaire ?'])
            ->assertOk()
            ->assertJsonPath('data.author.type', 'ceo');

        $this->postJson($this->url(), ['body' => 'Presque fini.', 'task_id' => $this->task->id], $this->as($this->dev))
            ->assertOk()
            ->assertJsonPath('data.author.agent_id', $this->dev->id)
            ->assertJsonPath('data.task_id', $this->task->id);

        // Le responsable de l'équipe participe sans être affecté à la tâche.
        $this->postJson($this->url(), ['body' => 'Je relis.'], $this->as($this->head))->assertOk();

        $response = $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.body', 'Où en est le formulaire ?')
            ->assertJsonPath('participants.agent_ids', [$this->head->id, $this->dev->id]);

        $firstId = $response->json('data.0.id');
        $this->getJson($this->url().'?after_id='.$firstId)->assertJsonCount(2, 'data');

        $entry = AuditEntry::query()->where('event', 'activity_message.created')->where('actor_id', $this->dev->id)->sole();
        $this->assertSame('agent', $entry->actor_type->value);
    }

    public function test_an_agent_outside_the_activity_cannot_write(): void
    {
        $this->postJson($this->url(), ['body' => 'Bonjour'], $this->as($this->outsider))->assertForbidden();
        $this->postJson($this->url('context'), ['title' => 'x', 'content' => 'y'], $this->as($this->outsider))->assertForbidden();
    }

    /**
     * Les fichiers ne quittent jamais l'hôte : un message n'en porte que la référence.
     */
    public function test_a_handoff_carries_file_references_only(): void
    {
        $device = Device::factory()->for($this->company)->create();
        $foreign = Device::factory()->create();

        $this->postJson($this->url(), [
            'kind' => 'handoff',
            'body' => 'Maquette prête.',
            'file_references' => [['label' => 'Maquette', 'path' => 'docs/maquette.png', 'device_id' => $device->id]],
        ], $this->as($this->dev))
            ->assertOk()
            ->assertJsonPath('data.kind', 'handoff')
            ->assertJsonPath('data.file_references.0.path', 'docs/maquette.png');

        $this->postJson($this->url(), [
            'body' => 'Fichier ailleurs',
            'file_references' => [['label' => 'x', 'path' => 'a', 'device_id' => $foreign->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('file_references.0.device_id');
    }

    public function test_only_the_ceo_removes_a_message(): void
    {
        $id = $this->postJson($this->url(), ['body' => 'À retirer'], $this->as($this->dev))->json('data.id');

        $this->deleteJson($this->url("messages/{$id}"), [], $this->as($this->dev))->assertForbidden();
        $this->deleteJson($this->url("messages/{$id}"))->assertNoContent();

        $this->getJson($this->url())->assertJsonCount(0, 'data');
        $this->assertSoftDeleted('activity_messages', ['id' => $id]);
    }

    public function test_the_shared_context_is_kept_by_participants(): void
    {
        $id = $this->postJson($this->url('context'), ['title' => 'Objectif', 'content' => 'Formulaire en ligne.'])
            ->assertOk()->json('data.id');

        $this->patchJson($this->url("context/{$id}"), ['content' => 'Formulaire en ligne, avec captcha.'], $this->as($this->dev))
            ->assertOk()
            ->assertJsonPath('data.content', 'Formulaire en ligne, avec captcha.');

        $this->postJson($this->url('context'), ['title' => 'Contrainte', 'content' => 'Pas de dépendance.', 'position' => 1], $this->as($this->head))->assertOk();

        $this->getJson($this->url('context'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Objectif');

        $this->deleteJson($this->url("context/{$id}"), [], $this->as($this->dev))->assertNoContent();
        $this->assertSoftDeleted('activity_context_items', ['id' => $id]);
    }

    public function test_conversations_are_isolated_between_companies(): void
    {
        $other = Company::factory()->create();
        $foreign = Activity::factory()->ofType(ActivityType::factory()->for($other)->create())->create();

        $this->getJson("/api/v1/activities/{$foreign->id}/messages")->assertNotFound();
        $this->postJson("/api/v1/activities/{$foreign->id}/messages", ['body' => 'x'])->assertNotFound();
        $this->getJson("/api/v1/activities/{$foreign->id}/context")->assertNotFound();

        // Une tâche d'une autre activité n'est pas acceptée.
        $otherTask = Task::factory()->in(
            Activity::factory()->ofType(ActivityType::factory()->for($this->company)->create())->create(),
            Unit::factory()->for($this->company)->create(),
        )->create();

        $this->postJson($this->url(), ['body' => 'x', 'task_id' => $otherTask->id])
            ->assertUnprocessable()->assertJsonValidationErrors('task_id');
    }
}
