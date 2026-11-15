<?php

namespace Tests\Feature\Api\V1;

use App\Enums\LifecycleStatus;
use App\Models\Agent;
use App\Models\AgentPositionAssignment;
use App\Models\Company;
use App\Models\Position;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Modèle studio : configuration de départ complète et adaptable.
 */
class StudioTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());

        $id = $this->postJson('/api/v1/company', ['name' => 'Studio Nord', 'template' => 'studio'])
            ->assertCreated()
            ->assertJsonPath('data.template', 'studio')
            ->json('data.id');

        $this->company = Company::query()->findOrFail($id);
    }

    /**
     * @return list<string>
     */
    private function childrenOf(string $department): array
    {
        $unit = $this->company->units()->where('name', $department)->whereNull('parent_id')->sole();

        /** @var list<string> $names */
        $names = $unit->children()->orderBy('sort_order')->pluck('name')->all();

        return $names;
    }

    /**
     * RM-04 : le modèle comprend ses huit départements.
     */
    public function test_rm04_the_studio_has_its_eight_departments(): void
    {
        $departments = $this->company->units()->whereNull('parent_id')->orderBy('sort_order')->pluck('name')->all();

        $this->assertSame([
            'Direction',
            'Produit',
            'Technique',
            'Qualité et sécurité',
            'Contenu',
            'Marketing et commercial',
            'Ressources humaines',
            'Administration et finances',
        ], $departments);
    }

    public function test_technique_has_its_four_poles(): void
    {
        $this->assertSame(
            ['Architecture', 'Développement', 'Déploiement', 'Maintenance'],
            $this->childrenOf('Technique'),
        );
    }

    /**
     * RM-07 : Qualité et sécurité reste indépendante des équipes de production.
     */
    public function test_rm07_quality_and_security_is_a_separate_department_with_two_poles(): void
    {
        $quality = $this->company->units()->where('name', 'Qualité et sécurité')->sole();
        $technique = $this->company->units()->where('name', 'Technique')->sole();

        $this->assertNull($quality->parent_id);
        $this->assertSame(['Tests et validation', 'Sécurité'], $this->childrenOf('Qualité et sécurité'));

        // Aucun poste Qualité et sécurité ne rend compte à un poste de Technique.
        $qualityUnitIds = [$quality->id, ...$quality->children()->pluck('id')->all()];
        $techniqueUnitIds = [$technique->id, ...$technique->children()->pluck('id')->all()];
        $techniquePositionIds = Position::query()->whereIn('unit_id', $techniqueUnitIds)->pluck('id');

        $this->assertSame(0, Position::query()
            ->whereIn('unit_id', $qualityUnitIds)
            ->whereIn('reports_to_id', $techniquePositionIds)
            ->count());
    }

    /**
     * RM-04 : les postes et agents précréés sont en brouillon.
     */
    public function test_rm04_every_precreated_position_and_agent_is_a_draft(): void
    {
        $this->assertGreaterThan(20, $this->company->positions()->count());
        $this->assertSame($this->company->positions()->count(), $this->company->agents()->count());

        $this->assertSame(0, $this->company->positions()->where('status', '!=', LifecycleStatus::Draft)->count());
        $this->assertSame(0, $this->company->agents()->where('status', '!=', LifecycleStatus::Draft)->count());
    }

    public function test_each_unit_has_exactly_one_head_and_each_agent_holds_one_position(): void
    {
        foreach ($this->company->units()->get() as $unit) {
            $this->assertSame(1, $unit->positions()->where('is_head', true)->count(), $unit->name);
        }

        foreach ($this->company->agents()->get() as $agent) {
            $this->assertNotNull($agent->position_id, $agent->name);
            $this->assertSame(1, $agent->assignments()->whereNull('ended_at')->count(), $agent->name);
        }

        $this->assertSame(
            $this->company->agents()->count(),
            Agent::query()->distinct()->count('position_id'),
        );
        $this->assertSame($this->company->agents()->count(), AgentPositionAssignment::query()->count());
    }

    public function test_the_hierarchy_leads_to_a_single_position_reporting_to_the_ceo(): void
    {
        $top = $this->company->positions()->whereNull('reports_to_id')->get();

        $this->assertCount(1, $top);
        $this->assertSame('Direction', $top[0]->unit?->name);

        foreach ($this->company->positions()->get() as $position) {
            $this->assertTrue($position->isSelfOrReportsTo($top[0]), $position->title);
        }
    }

    /**
     * RM-06 / RM-10 : le modèle ne contient ni modèle IA ni compte du CEO ;
     * aucun agent n'est donc activable tant que le CEO n'a rien connecté.
     */
    public function test_rm06_no_agent_is_activable_before_the_ceo_connects_a_model(): void
    {
        $this->assertSame(0, $this->company->aiProviders()->count());

        $agent = $this->company->agents()->firstOrFail();

        $this->postJson("/api/v1/agents/{$agent->id}/activate")->assertConflict();
    }

    /**
     * RM-04 : le modèle est adaptable ; RM-03 : rien n'est figé.
     */
    public function test_rm04_the_ceo_adapts_the_studio_freely(): void
    {
        $unit = $this->company->units()->where('name', 'Contenu')->sole();
        $position = $unit->positions()->where('is_head', false)->sole();
        $agent = $position->agents()->sole();

        $this->patchJson("/api/v1/units/{$unit->id}", ['name' => 'Éditorial'])->assertOk();
        $this->patchJson("/api/v1/positions/{$position->id}", ['title' => 'Journaliste', 'status' => 'active'])->assertOk();
        $this->patchJson("/api/v1/agents/{$agent->id}", ['name' => 'Renommé'])->assertOk();
        $this->postJson('/api/v1/units', ['name' => 'Recherche', 'kind' => 'Département'])->assertCreated();

        $this->assertSame(9, $this->company->units()->whereNull('parent_id')->count());
    }

    /**
     * RM-03 : le modèle studio n'est pas imposé aux autres entreprises.
     */
    public function test_rm03_another_company_created_blank_stays_empty(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/company', ['name' => 'Cabinet HSE', 'template' => 'blank'])->assertCreated();

        // Seules les unités système (D-013) existent, sans agent.
        $this->getJson('/api/v1/units')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/positions')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/agents')->assertJsonCount(0, 'data');
        $this->assertGreaterThan(2, Unit::query()->count());
    }

    /**
     * D-013 : le studio porte lui-même ses unités système, sans doublon.
     */
    public function test_the_studio_carries_its_system_units_without_duplicates(): void
    {
        $hr = $this->company->units()->where('system_role', 'hr')->sole();
        $facilities = $this->company->units()->where('system_role', 'facilities')->sole();

        $this->assertSame('Ressources humaines', $hr->name);
        $this->assertSame('Moyens généraux', $facilities->name);
        $this->assertSame('Administration et finances', $facilities->parent?->name);
    }

    public function test_the_whole_studio_is_reachable_through_the_api(): void
    {
        $this->getJson('/api/v1/units')->assertOk()->assertJsonCount($this->company->units()->count(), 'data');
        $this->getJson('/api/v1/positions')->assertOk()->assertJsonCount($this->company->positions()->count(), 'data');
        $this->getJson('/api/v1/agents')->assertOk()->assertJsonCount($this->company->agents()->count(), 'data');
    }
}
