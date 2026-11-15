<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\Position;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PositionTest extends TestCase
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

    public function test_a_position_carries_mission_responsibilities_and_hierarchy(): void
    {
        $head = $this->postJson('/api/v1/positions', [
            'title' => 'Responsable technique',
            'unit_id' => $this->unit->id,
            'mission' => 'Garantir la qualité technique des livrables.',
            'responsibilities' => ['Valider les choix techniques', 'Organiser son équipe'],
            'is_head' => true,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_head', true)
            ->assertJsonPath('data.reports_to_id', null)
            ->assertJsonPath('data.responsibilities.1', 'Organiser son équipe')
            ->json('data.id');

        $this->postJson('/api/v1/positions', [
            'title' => 'Développeur',
            'unit_id' => $this->unit->id,
            'reports_to_id' => $head,
            'status' => 'draft',
        ])->assertCreated()
            ->assertJsonPath('data.reports_to_id', $head)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.responsibilities', []);
    }

    public function test_creation_validates_its_input(): void
    {
        $this->postJson('/api/v1/positions', [
            'title' => '',
            'unit_id' => 999,
            'status' => 'retraité',
            'responsibilities' => 'pas une liste',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'unit_id', 'status', 'responsibilities']);
    }

    public function test_unit_and_superior_must_belong_to_the_same_company(): void
    {
        $foreignUnit = Unit::factory()->create();
        $foreignPosition = Position::factory()->in($foreignUnit)->create();

        $this->postJson('/api/v1/positions', ['title' => 'Intrus', 'unit_id' => $foreignUnit->id])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_id');

        $this->postJson('/api/v1/positions', [
            'title' => 'Intrus',
            'unit_id' => $this->unit->id,
            'reports_to_id' => $foreignPosition->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reports_to_id');

        $this->getJson("/api/v1/positions/{$foreignPosition->id}")->assertNotFound();
        $this->deleteJson("/api/v1/positions/{$foreignPosition->id}")->assertNotFound();
    }

    public function test_positions_are_listed_and_filtered_by_unit(): void
    {
        $other = Unit::factory()->for($this->company)->create();
        Position::factory()->in($this->unit)->count(2)->create();
        $alone = Position::factory()->in($other)->create();
        Position::factory()->create();

        $this->getJson('/api/v1/positions')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/positions?unit_id={$other->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $alone->id);
    }

    public function test_a_position_cannot_report_to_itself_or_to_its_own_report(): void
    {
        $head = Position::factory()->in($this->unit)->create();
        $lead = Position::factory()->in($this->unit)->create(['reports_to_id' => $head->id]);
        $member = Position::factory()->in($this->unit)->create(['reports_to_id' => $lead->id]);

        $this->patchJson("/api/v1/positions/{$head->id}", ['reports_to_id' => $head->id])
            ->assertUnprocessable()->assertJsonValidationErrors('reports_to_id');

        $this->patchJson("/api/v1/positions/{$head->id}", ['reports_to_id' => $member->id])
            ->assertUnprocessable()->assertJsonValidationErrors('reports_to_id');

        $this->patchJson("/api/v1/positions/{$member->id}", ['reports_to_id' => $head->id])
            ->assertOk()->assertJsonPath('data.reports_to_id', $head->id);
    }

    /**
     * RM-04 : un poste en brouillon s'adapte puis se met en service.
     */
    public function test_rm04_a_draft_position_is_adapted_then_activated(): void
    {
        $position = Position::factory()->in($this->unit)->draft()->create();

        $this->patchJson("/api/v1/positions/{$position->id}", [
            'title' => 'Chargé de produit',
            'mission' => 'Cadrer les besoins.',
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Chargé de produit')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_a_position_others_report_to_cannot_be_trashed(): void
    {
        $head = Position::factory()->in($this->unit)->create();
        Position::factory()->in($this->unit)->create(['reports_to_id' => $head->id]);

        $this->deleteJson("/api/v1/positions/{$head->id}")->assertConflict();
        $this->assertNotSoftDeleted($head);
    }

    public function test_a_position_goes_to_the_trash_and_comes_back(): void
    {
        $position = Position::factory()->in($this->unit)->create();

        $this->deleteJson("/api/v1/positions/{$position->id}")->assertNoContent();
        $this->assertSoftDeleted($position);
        $this->getJson('/api/v1/positions')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/positions?trashed=1')->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/positions/{$position->id}/restore")->assertOk();
        $this->assertNotSoftDeleted($position);
    }

    public function test_a_position_cannot_be_restored_while_its_unit_is_trashed(): void
    {
        $position = Position::factory()->in($this->unit)->create();
        $position->delete();
        $this->unit->delete();

        $this->postJson("/api/v1/positions/{$position->id}/restore")->assertConflict();
    }
}
