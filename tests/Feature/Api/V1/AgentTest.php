<?php

namespace Tests\Feature\Api\V1;

use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Position;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->unit = Unit::factory()->for($this->company)->create();
        Sanctum::actingAs($this->company->user);
    }

    private function position(string $title = 'Développeur'): Position
    {
        return Position::factory()->in($this->unit)->create(['title' => $title]);
    }

    private function aiModel(?AiProvider $provider = null): AiModel
    {
        return AiModel::factory()
            ->of($provider ?? AiProvider::factory()->for($this->company)->create())
            ->create();
    }

    public function test_an_agent_is_created_as_a_draft_with_or_without_a_position(): void
    {
        $this->postJson('/api/v1/agents', ['name' => 'Noé', 'bio' => 'Curieux et méthodique.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.position_id', null)
            ->assertJsonPath('data.ai_models', []);

        $position = $this->position();

        $id = $this->postJson('/api/v1/agents', ['name' => 'Lina', 'position_id' => $position->id])
            ->assertCreated()
            ->assertJsonPath('data.position_id', $position->id)
            ->json('data.id');

        $this->getJson("/api/v1/agents/{$id}/assignments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.position_title', 'Développeur')
            ->assertJsonPath('data.0.ended_at', null);
    }

    public function test_creation_validates_its_input_and_refuses_a_foreign_position(): void
    {
        $foreign = Position::factory()->create();

        $this->postJson('/api/v1/agents', ['name' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/agents', ['name' => 'Intrus', 'position_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('position_id');
    }

    public function test_an_agent_of_another_company_is_unreachable(): void
    {
        $foreign = Agent::factory()->create();

        $this->getJson("/api/v1/agents/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/v1/agents/{$foreign->id}", ['name' => 'Piraté'])->assertNotFound();
        $this->postJson("/api/v1/agents/{$foreign->id}/activate")->assertNotFound();
        $this->postJson("/api/v1/agents/{$foreign->id}/position", ['position_id' => null])->assertNotFound();
        $this->getJson("/api/v1/agents/{$foreign->id}/assignments")->assertNotFound();
        $this->deleteJson("/api/v1/agents/{$foreign->id}")->assertNotFound();
        $this->getJson('/api/v1/agents')->assertJsonCount(0, 'data');
    }

    public function test_agents_are_filtered_by_position_and_status(): void
    {
        $position = $this->position();
        Agent::factory()->for($this->company)->create(['position_id' => $position->id]);
        Agent::factory()->for($this->company)->active()->create();
        Agent::factory()->create();

        $this->getJson('/api/v1/agents')->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/agents?position_id={$position->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/agents?status=active')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/agents?status=inconnu')->assertUnprocessable();
    }

    /**
     * RM-05 : un agent occupe un seul poste à la fois et garde son identité
     * et son historique quand il change de poste.
     */
    public function test_rm05_changing_position_keeps_identity_and_history(): void
    {
        $developer = $this->position('Développeur');
        $lead = $this->position('Responsable technique');

        $id = $this->postJson('/api/v1/agents', [
            'name' => 'Lina',
            'bio' => 'Dix ans de développement mobile.',
            'position_id' => $developer->id,
        ])->json('data.id');

        $this->travel(30)->days();

        $this->postJson("/api/v1/agents/{$id}/position", ['position_id' => $lead->id])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'Lina')
            ->assertJsonPath('data.bio', 'Dix ans de développement mobile.')
            ->assertJsonPath('data.position_id', $lead->id);

        $history = $this->getJson("/api/v1/agents/{$id}/assignments")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.position_title', 'Responsable technique')
            ->assertJsonPath('data.0.ended_at', null)
            ->assertJsonPath('data.1.position_title', 'Développeur')
            ->json('data');

        $this->assertNotNull($history[1]['ended_at']);
        $this->assertSame($history[0]['started_at'], $history[1]['ended_at']);
        $this->assertSame(1, Agent::query()->count());
    }

    /**
     * RM-05 : le parcours reste lisible même si un ancien poste est à la corbeille.
     */
    public function test_rm05_history_survives_the_removal_of_a_former_position(): void
    {
        $old = $this->position('Stagiaire');
        $new = $this->position('Développeur');
        $agent = Agent::factory()->for($this->company)->create();

        $this->postJson("/api/v1/agents/{$agent->id}/position", ['position_id' => $old->id])->assertOk();
        $this->postJson("/api/v1/agents/{$agent->id}/position", ['position_id' => $new->id])->assertOk();
        $this->deleteJson("/api/v1/positions/{$old->id}")->assertNoContent();

        $this->getJson("/api/v1/agents/{$agent->id}/assignments")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.position_title', 'Stagiaire');
    }

    public function test_an_agent_can_be_removed_from_any_position(): void
    {
        $position = $this->position();
        $agent = Agent::factory()->for($this->company)->create();
        $this->postJson("/api/v1/agents/{$agent->id}/position", ['position_id' => $position->id]);

        $this->postJson("/api/v1/agents/{$agent->id}/position", ['position_id' => null])
            ->assertOk()->assertJsonPath('data.position_id', null);

        $this->getJson("/api/v1/agents/{$agent->id}/assignments")->assertJsonCount(1, 'data');
        $this->assertNotNull($agent->assignments()->sole()->ended_at);

        $this->postJson("/api/v1/agents/{$agent->id}/position", [])->assertUnprocessable();
    }

    public function test_reassigning_to_the_same_position_does_not_add_history(): void
    {
        $position = $this->position();
        $agent = Agent::factory()->for($this->company)->create();

        $this->postJson("/api/v1/agents/{$agent->id}/position", ['position_id' => $position->id]);
        $this->postJson("/api/v1/agents/{$agent->id}/position", ['position_id' => $position->id])->assertOk();

        $this->assertSame(1, $agent->assignments()->count());
    }

    /**
     * RM-05 : la base refuse deux affectations ouvertes pour un même agent.
     */
    public function test_rm05_the_database_refuses_two_open_assignments(): void
    {
        $agent = Agent::factory()->for($this->company)->create();
        $agent->assignments()->create(['position_id' => $this->position()->id, 'started_at' => now()]);

        $this->expectException(QueryException::class);

        $agent->assignments()->create(['position_id' => $this->position()->id, 'started_at' => now()]);
    }

    public function test_an_occupied_position_cannot_be_trashed(): void
    {
        $position = $this->position();
        $agent = Agent::factory()->for($this->company)->create();
        $this->postJson("/api/v1/agents/{$agent->id}/position", ['position_id' => $position->id]);

        $this->deleteJson("/api/v1/positions/{$position->id}")->assertConflict();
        $this->assertNotSoftDeleted($position);
    }

    /**
     * RM-10 : un agent peut être branché à plusieurs modèles IA, de
     * fournisseurs différents, chacun avec son usage.
     */
    public function test_rm10_an_agent_uses_several_models_from_several_providers(): void
    {
        $agent = Agent::factory()->for($this->company)->create();
        $writing = $this->aiModel();
        $coding = $this->aiModel();
        $review = $this->aiModel($writing->provider);

        $this->putJson("/api/v1/agents/{$agent->id}/ai-models", ['models' => [
            ['ai_model_id' => $writing->id, 'purpose' => 'Rédaction', 'is_default' => true],
            ['ai_model_id' => $coding->id, 'purpose' => 'Code'],
            ['ai_model_id' => $review->id],
        ]])->assertOk()
            ->assertJsonCount(3, 'data.ai_models')
            ->assertJsonPath('data.ai_models.0.purpose', 'Rédaction')
            ->assertJsonPath('data.ai_models.0.is_default', true)
            ->assertJsonPath('data.ai_models.1.is_default', false)
            ->assertJsonPath('data.ai_models.2.purpose', null);

        $this->assertNotSame($writing->ai_provider_id, $coding->ai_provider_id);

        // La liste est remplacée, pas complétée.
        $this->putJson("/api/v1/agents/{$agent->id}/ai-models", ['models' => [
            ['ai_model_id' => $coding->id, 'purpose' => 'Code et revue'],
        ]])->assertOk()
            ->assertJsonCount(1, 'data.ai_models')
            ->assertJsonPath('data.ai_models.0.purpose', 'Code et revue');
    }

    public function test_model_assignment_validates_its_input(): void
    {
        $agent = Agent::factory()->for($this->company)->create();
        $model = $this->aiModel();
        $other = $this->aiModel();
        $foreign = AiModel::factory()->create();

        $this->putJson("/api/v1/agents/{$agent->id}/ai-models", [])->assertUnprocessable();

        $this->putJson("/api/v1/agents/{$agent->id}/ai-models", ['models' => [
            ['ai_model_id' => $foreign->id],
        ]])->assertUnprocessable()->assertJsonValidationErrors('models.0.ai_model_id');

        $this->putJson("/api/v1/agents/{$agent->id}/ai-models", ['models' => [
            ['ai_model_id' => $model->id],
            ['ai_model_id' => $model->id],
        ]])->assertUnprocessable();

        $this->putJson("/api/v1/agents/{$agent->id}/ai-models", ['models' => [
            ['ai_model_id' => $model->id, 'is_default' => true],
            ['ai_model_id' => $other->id, 'is_default' => true],
        ]])->assertUnprocessable()->assertJsonValidationErrors('models');
    }

    /**
     * RM-06 : un agent est activable dès qu'un modèle IA lui est configuré ;
     * aucun accès MCP ni poste n'est exigé.
     */
    public function test_rm06_an_agent_is_activated_as_soon_as_a_model_is_configured(): void
    {
        $agent = Agent::factory()->for($this->company)->create();

        $this->postJson("/api/v1/agents/{$agent->id}/activate")->assertConflict();
        $this->assertSame('draft', $agent->fresh()?->status->value);

        $this->putJson("/api/v1/agents/{$agent->id}/ai-models", ['models' => [
            ['ai_model_id' => $this->aiModel()->id],
        ]])->assertOk();

        $this->postJson("/api/v1/agents/{$agent->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.position_id', null);
    }

    /**
     * RM-06 : un modèle dont le fournisseur a été retiré ne suffit pas.
     */
    public function test_rm06_a_model_of_a_removed_provider_does_not_allow_activation(): void
    {
        $agent = Agent::factory()->for($this->company)->create();
        $model = $this->aiModel();
        $agent->aiModels()->attach($model->id);
        $model->provider?->delete();

        $this->postJson("/api/v1/agents/{$agent->id}/activate")->assertConflict();
    }

    /**
     * RM-06 : un agent en service garde toujours au moins un modèle.
     */
    public function test_rm06_an_active_agent_cannot_lose_its_last_model(): void
    {
        $agent = Agent::factory()->for($this->company)->active()->create();
        $model = $this->aiModel();
        $agent->aiModels()->attach($model->id);

        $this->putJson("/api/v1/agents/{$agent->id}/ai-models", ['models' => []])->assertConflict();
        $this->deleteJson("/api/v1/ai-models/{$model->id}")->assertConflict();
        $this->deleteJson("/api/v1/ai-providers/{$model->ai_provider_id}")->assertConflict();
        $this->assertSame(1, $agent->aiModels()->count());

        // Une fois repassé en brouillon, tout redevient possible.
        $this->postJson("/api/v1/agents/{$agent->id}/deactivate")
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->putJson("/api/v1/agents/{$agent->id}/ai-models", ['models' => []])
            ->assertOk()->assertJsonCount(0, 'data.ai_models');
    }

    public function test_identity_is_edited_without_touching_position_or_status(): void
    {
        $position = $this->position();
        $agent = Agent::factory()->for($this->company)->create(['position_id' => $position->id]);

        $this->patchJson("/api/v1/agents/{$agent->id}", [
            'name' => 'Lina B.',
            'status' => 'active',
            'position_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Lina B.')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.position_id', $position->id);
    }

    public function test_an_agent_goes_to_the_trash_and_comes_back_with_its_history(): void
    {
        $position = $this->position();
        $agent = Agent::factory()->for($this->company)->create();
        $this->postJson("/api/v1/agents/{$agent->id}/position", ['position_id' => $position->id]);

        $this->deleteJson("/api/v1/agents/{$agent->id}")->assertNoContent();
        $this->assertSoftDeleted($agent);
        $this->getJson('/api/v1/agents')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/agents?trashed=1')->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/agents/{$agent->id}/restore")
            ->assertOk()->assertJsonPath('data.position_id', $position->id);
        $this->getJson("/api/v1/agents/{$agent->id}/assignments")->assertJsonCount(1, 'data');
    }

    /**
     * RM-34 : changement de poste, modèles et mise en service sont tracés.
     */
    public function test_rm34_agent_events_are_written_to_the_audit_log(): void
    {
        $position = $this->position();
        $model = $this->aiModel();
        $id = $this->postJson('/api/v1/agents', ['name' => 'Lina'])->json('data.id');

        $this->postJson("/api/v1/agents/{$id}/position", ['position_id' => $position->id]);
        $this->putJson("/api/v1/agents/{$id}/ai-models", ['models' => [['ai_model_id' => $model->id]]]);
        $this->postJson("/api/v1/agents/{$id}/activate");

        $entries = AuditEntry::query()
            ->where('subject_type', (new Agent)->getMorphClass())
            ->where('subject_id', $id)
            ->orderBy('id')
            ->get();

        $this->assertSame(
            ['agent.created', 'agent.updated', 'agent.position_changed', 'agent.ai_models_changed', 'agent.updated'],
            $entries->pluck('event')->all(),
        );
        $this->assertSame(
            ['from_position_id' => null, 'to_position_id' => $position->id],
            $entries[2]->properties,
        );
        $this->assertSame(['status' => 'active'], $entries[4]->properties['after'] ?? null);
    }
}
