<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Sanctum::actingAs($this->company->user);
    }

    public function test_the_catalog_lists_known_provider_types(): void
    {
        $this->getJson('/api/v1/ai-providers/catalog')
            ->assertOk()
            ->assertJsonFragment(['driver' => 'anthropic', 'label' => 'Anthropic'])
            ->assertJsonFragment(['driver' => 'openrouter', 'label' => 'OpenRouter']);
    }

    /**
     * RM-10 : plusieurs fournisseurs de modèles peuvent être connectés.
     */
    public function test_rm10_several_providers_are_connected_each_with_its_models(): void
    {
        $anthropic = $this->postJson('/api/v1/ai-providers', ['name' => 'Anthropic', 'driver' => 'anthropic'])
            ->assertCreated()->assertJsonPath('data.models', [])->json('data.id');

        $router = $this->postJson('/api/v1/ai-providers', [
            'name' => 'Passerelle interne',
            'driver' => 'openrouter',
            'base_url' => 'https://ia.example.test/v1',
        ])->assertCreated()->assertJsonPath('data.base_url', 'https://ia.example.test/v1')->json('data.id');

        $this->postJson("/api/v1/ai-providers/{$anthropic}/models", [
            'identifier' => 'claude-opus-5-5',
            'label' => 'Opus',
        ])->assertCreated()->assertJsonPath('data.ai_provider_id', $anthropic);

        $this->postJson("/api/v1/ai-providers/{$router}/models", ['identifier' => 'un/modele'])
            ->assertCreated()->assertJsonPath('data.label', null);

        $this->getJson('/api/v1/ai-providers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.models.0.identifier', 'claude-opus-5-5')
            ->assertJsonPath('data.1.models.0.identifier', 'un/modele');
    }

    public function test_a_provider_type_outside_the_catalog_is_accepted(): void
    {
        $this->postJson('/api/v1/ai-providers', ['name' => 'Maison', 'driver' => 'fournisseur-maison'])
            ->assertCreated()->assertJsonPath('data.driver', 'fournisseur-maison');
    }

    public function test_creation_validates_its_input(): void
    {
        $this->postJson('/api/v1/ai-providers', [
            'name' => '',
            'driver' => 'Pas Un Slug',
            'base_url' => 'ftp://exemple.test',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'driver', 'base_url']);
    }

    /**
     * Les clés d'API ne passent jamais par le serveur.
     */
    public function test_no_api_key_is_ever_stored_on_the_server(): void
    {
        $this->postJson('/api/v1/ai-providers', [
            'name' => 'Anthropic',
            'driver' => 'anthropic',
            'api_key' => 'cle-qui-ne-doit-pas-etre-gardee',
        ])->assertCreated()->assertJsonMissingPath('data.api_key');

        foreach (Schema::getColumnListing('ai_providers') as $column) {
            $this->assertStringNotContainsString('key', $column);
            $this->assertStringNotContainsString('secret', $column);
            $this->assertStringNotContainsString('token', $column);
        }

        $this->assertDatabaseMissing('audit_entries', ['event' => 'ai_provider.created', 'properties->api_key' => 'cle-qui-ne-doit-pas-etre-gardee']);
    }

    public function test_a_model_identifier_is_unique_per_provider(): void
    {
        $provider = AiProvider::factory()->for($this->company)->create();
        $other = AiProvider::factory()->for($this->company)->create();
        AiModel::factory()->of($provider)->create(['identifier' => 'modele-x']);

        $this->postJson("/api/v1/ai-providers/{$provider->id}/models", ['identifier' => 'modele-x'])
            ->assertUnprocessable()->assertJsonValidationErrors('identifier');

        $this->postJson("/api/v1/ai-providers/{$other->id}/models", ['identifier' => 'modele-x'])
            ->assertCreated();
    }

    public function test_a_provider_and_a_model_are_edited(): void
    {
        $provider = AiProvider::factory()->for($this->company)->create();
        $model = AiModel::factory()->of($provider)->create();

        $this->patchJson("/api/v1/ai-providers/{$provider->id}", ['name' => 'Compte principal'])
            ->assertOk()->assertJsonPath('data.name', 'Compte principal');

        $this->patchJson("/api/v1/ai-models/{$model->id}", ['label' => 'Rapide'])
            ->assertOk()->assertJsonPath('data.label', 'Rapide');
    }

    public function test_an_unused_model_and_provider_are_removed(): void
    {
        $provider = AiProvider::factory()->for($this->company)->create();
        $model = AiModel::factory()->of($provider)->create();
        $kept = AiModel::factory()->of($provider)->create();

        $this->deleteJson("/api/v1/ai-models/{$model->id}")->assertNoContent();
        $this->assertSoftDeleted($model);
        $this->getJson("/api/v1/ai-providers/{$provider->id}")->assertJsonCount(1, 'data.models');

        $this->deleteJson("/api/v1/ai-providers/{$provider->id}")->assertNoContent();
        $this->assertSoftDeleted($provider);
        $this->getJson('/api/v1/ai-providers')->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/ai-models/{$kept->id}", ['label' => 'x'])->assertNotFound();
    }

    public function test_providers_and_models_of_another_company_are_unreachable(): void
    {
        $foreign = AiProvider::factory()->create();
        $foreignModel = AiModel::factory()->of($foreign)->create();

        $this->getJson("/api/v1/ai-providers/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/v1/ai-providers/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/ai-providers/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/v1/ai-providers/{$foreign->id}/models", ['identifier' => 'x'])->assertNotFound();
        $this->patchJson("/api/v1/ai-models/{$foreignModel->id}", ['label' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/ai-models/{$foreignModel->id}")->assertNotFound();
        $this->getJson('/api/v1/ai-providers')->assertJsonCount(0, 'data');
    }
}
