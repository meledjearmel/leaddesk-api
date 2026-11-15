<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Office\ManageOfficePlans;
use App\Enums\LifecycleStatus;
use App\Models\Agent;
use App\Models\Company;
use App\Models\Mission;
use App\Models\User;
use App\Office\OfficePlanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Déclenchement automatique d'un réaménagement (D-012, D-039).
 */
class OfficeLayoutTriggerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $hr;

    private string $facilities;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/company', ['name' => 'Atelier Nord', 'template' => 'blank'])->assertCreated();
        $this->company = Company::query()->sole();
        $this->hr = (string) $this->company->units()->where('system_role', 'hr')->sole()->id;
        $this->facilities = (string) $this->company->units()->where('system_role', 'facilities')->sole()->id;
    }

    /**
     * Plan en vigueur : un bureau individuel (un poste) par unité.
     */
    private function putPlanInEffect(): void
    {
        $this->putJson('/api/v1/office/plan', ['layout' => [
            'cols' => 20,
            'rows' => 10,
            'rooms' => [
                ['id' => 'rh', 'type' => 'private_office', 'department' => $this->hr, 'x' => 0, 'y' => 0, 'cols' => 3, 'rows' => 3],
                ['id' => 'mg', 'type' => 'private_office', 'department' => $this->facilities, 'x' => 4, 'y' => 0, 'cols' => 3, 'rows' => 3],
            ],
        ]])->assertCreated();
    }

    private function openRequests(): int
    {
        return $this->company->missions()
            ->where('kind', Mission::KIND_OFFICE_LAYOUT)
            ->whereIn('status', ['received', 'in_progress'])
            ->count();
    }

    private function hire(string $unit, string $title): void
    {
        $this->postJson('/api/v1/positions', ['unit_id' => (int) $unit, 'title' => $title])->assertCreated();
    }

    public function test_without_a_plan_in_effect_nothing_is_requested(): void
    {
        $this->hire($this->hr, 'Recruteur');

        $this->assertSame(0, $this->company->missions()->count());
    }

    /**
     * Un poste de plus ne tient plus dans le plan : les Moyens généraux sont saisis.
     */
    public function test_a_new_position_that_no_longer_fits_requests_a_new_layout(): void
    {
        $this->putPlanInEffect();
        $this->assertSame(0, $this->openRequests());

        $this->hire($this->hr, 'Recruteur');

        $mission = $this->company->missions()->sole();
        $this->assertSame(Mission::KIND_OFFICE_LAYOUT, $mission->kind);
        $this->assertSame('received', $mission->status);
        $this->assertStringContainsString('Ressources humaines : 1 postes pour 2 personnes.', $mission->brief);
        $this->assertSame('Responsable Moyens généraux', $this->company->positions()->findOrFail($mission->position_id)->title);
        $this->assertNull($mission->agent_id);
    }

    public function test_a_new_department_requests_a_new_layout(): void
    {
        $this->putPlanInEffect();

        $this->postJson('/api/v1/units', ['name' => 'Recherche', 'kind' => 'Département'])->assertCreated();

        $this->assertStringContainsString('Le département « Recherche » n\'a pas encore de salle.', $this->company->missions()->sole()->brief);
    }

    /**
     * Changements rapprochés regroupés : une seule demande ouverte à la fois.
     */
    public function test_close_changes_give_a_single_request(): void
    {
        $this->putPlanInEffect();

        $this->hire($this->hr, 'Recruteur');
        $this->hire($this->hr, 'Formateur');
        $this->postJson('/api/v1/units', ['name' => 'Recherche'])->assertCreated();

        $this->assertSame(1, $this->openRequests());
    }

    public function test_a_change_that_still_fits_requests_nothing(): void
    {
        $this->putPlanInEffect();

        $unit = $this->company->units()->findOrFail((int) $this->hr);
        $this->patchJson("/api/v1/units/{$unit->id}", ['name' => 'Personnes et talents'])->assertOk();

        $this->assertSame(0, $this->company->missions()->count());
    }

    /**
     * La mission va à l'agent en service du poste, et se ferme quand il propose.
     */
    public function test_the_request_goes_to_the_agent_and_closes_when_he_proposes(): void
    {
        $position = $this->company->positions()->where('unit_id', (int) $this->facilities)->sole();
        $agent = $this->company->agents()->create(['name' => 'Lisandre', 'status' => LifecycleStatus::Active, 'position_id' => $position->id]);
        $this->putPlanInEffect();

        $this->hire($this->hr, 'Recruteur');
        $mission = $this->company->missions()->sole();
        $this->assertSame($agent->id, $mission->agent_id);

        app(ManageOfficePlans::class)->propose($this->company, Agent::query()->findOrFail($agent->id), OfficePlanValidator::normalize([
            'cols' => 20,
            'rows' => 10,
            'rooms' => [
                ['id' => 'rh', 'type' => 'private_office', 'department' => $this->hr, 'x' => 0, 'y' => 0, 'cols' => 3, 'rows' => 4],
                ['id' => 'rh2', 'type' => 'private_office', 'department' => $this->hr, 'x' => 0, 'y' => 5, 'cols' => 3, 'rows' => 3],
                ['id' => 'mg', 'type' => 'private_office', 'department' => $this->facilities, 'x' => 4, 'y' => 0, 'cols' => 3, 'rows' => 3],
            ],
        ]), 'Le recruteur arrive');

        $mission->refresh();
        $this->assertSame('done', $mission->status);
        $this->assertStringContainsString('version 2', (string) $mission->outcome);
        $this->assertSame(0, $this->openRequests());
    }

    /**
     * RM-03 : la demande va au poste qui a l'outil, quel que soit son nom.
     */
    public function test_rm03_the_request_goes_to_whichever_position_holds_the_tool(): void
    {
        $this->company->positions()->update(['tool_permissions' => null]);
        $this->putPlanInEffect();
        $this->hire($this->hr, 'Recruteur');
        $this->assertSame(0, $this->company->missions()->count());

        $office = $this->company->positions()->where('title', 'Recruteur')->sole();
        $office->update(['tool_permissions' => ['office.propose_plan' => 'allow']]);

        $this->assertSame($office->id, $this->company->missions()->sole()->position_id);
    }

    public function test_the_mission_kind_is_exposed_by_the_api(): void
    {
        $this->putPlanInEffect();
        $this->hire($this->hr, 'Recruteur');

        $this->getJson('/api/v1/missions')->assertOk()->assertJsonPath('data.0.kind', 'office_layout');
    }
}
