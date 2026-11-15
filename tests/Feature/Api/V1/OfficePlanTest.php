<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Office\ManageOfficePlans;
use App\Enums\LifecycleStatus;
use App\Enums\OfficePlanStatus;
use App\Models\Agent;
use App\Models\Company;
use App\Models\OfficePlan;
use App\Models\User;
use App\Office\OfficeDepartment;
use App\Office\OfficePlanValidator;
use App\Office\RoomCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plans d'aménagement du bureau (D-012, D-036).
 */
class OfficePlanTest extends TestCase
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
     * Plan valable pour l'entreprise vierge : un poste dans chaque unité système.
     *
     * @return array<string, mixed>
     */
    private function layout(int $kitchenX = 10): array
    {
        return [
            'version' => 1,
            'cols' => 20,
            'rows' => 10,
            'rooms' => [
                ['id' => 'hr', 'type' => 'private_office', 'department' => $this->hr, 'x' => 0, 'y' => 0, 'cols' => 3, 'rows' => 3],
                ['id' => 'mg', 'type' => 'private_office', 'department' => $this->facilities, 'x' => 4, 'y' => 0, 'cols' => 3, 'rows' => 3],
                ['id' => 'cafe', 'type' => 'micro_kitchen', 'x' => $kitchenX, 'y' => 0, 'cols' => 4, 'rows' => 3],
            ],
        ];
    }

    private function facilitiesAgent(): Agent
    {
        $position = $this->company->positions()->where('unit_id', (int) $this->facilities)->sole();

        return $this->company->agents()->create([
            'name' => 'Lisandre',
            'status' => LifecycleStatus::Active,
            'position_id' => $position->id,
        ]);
    }

    private function propose(int $kitchenX = 10): OfficePlan
    {
        return app(ManageOfficePlans::class)->propose($this->company, $this->facilitiesAgent(), OfficePlanValidator::normalize($this->layout($kitchenX)), 'Nouvelle embauche');
    }

    /**
     * Les cas de `tests/Fixtures/office/validation-cases.json` sont calculés par
     * `OfficePlan.validate` de l'application : le serveur donne exactement les
     * mêmes problèmes, dans le même ordre.
     */
    public function test_the_validator_matches_the_application_case_by_case(): void
    {
        $raw = file_get_contents(base_path('tests/Fixtures/office/validation-cases.json'));
        $this->assertIsString($raw);
        /** @var list<array{name: string, departments: list<array{id: string, name: string, headcount: int}>, layout: array<string, mixed>, problems: list<string>}> $cases */
        $cases = json_decode($raw, true);
        $this->assertGreaterThanOrEqual(6, count($cases));

        $validator = app(OfficePlanValidator::class);

        foreach ($cases as $case) {
            $departments = array_map(fn (array $d): OfficeDepartment => new OfficeDepartment($d['id'], $d['name'], $d['headcount']), $case['departments']);
            $this->assertSame($case['problems'], $validator->validate($case['layout'], $departments), $case['name']);
        }
    }

    /**
     * RM-03 : le catalogue sert des activités, jamais un département nommé.
     */
    public function test_rm03_the_room_catalog_names_no_department(): void
    {
        $types = $this->getJson('/api/v1/office/room-types')->assertOk()->json('data');

        $this->assertCount(count(app(RoomCatalog::class)->all()), $types);
        foreach ($types as $type) {
            $this->assertContains($type['scope'], ['department', 'shared'], $type['id']);
            $this->assertArrayNotHasKey('department', $type);
        }
    }

    public function test_departments_are_the_top_level_units_with_their_headcount(): void
    {
        $this->getJson('/api/v1/office/departments')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $this->hr, 'name' => 'Ressources humaines', 'headcount' => 1, 'color' => '#B58BD9', 'tags' => ['recrutement', 'formation', 'calme']],
                ['id' => $this->facilities, 'name' => 'Moyens généraux', 'headcount' => 1, 'color' => '#8C9A5B', 'tags' => ['documents']],
            ]]);
    }

    /**
     * D-037 et RM-03 : les activités d'une unité se règlent librement, parmi
     * les étiquettes connues du catalogue.
     */
    public function test_rm03_unit_activity_tags_are_configurable_among_known_tags(): void
    {
        $this->getJson('/api/v1/office/activity-tags')->assertOk()->assertJsonFragment(['code']);

        $this->patchJson("/api/v1/units/{$this->hr}", ['activity_tags' => ['code', 'tests']])
            ->assertOk()
            ->assertJsonPath('data.activity_tags', ['code', 'tests']);

        $this->patchJson("/api/v1/units/{$this->hr}", ['activity_tags' => ['teleportation']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['activity_tags.0']);
    }

    public function test_the_studio_template_gives_each_department_its_activities(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/company', ['name' => 'Studio', 'template' => 'studio'])->assertCreated();

        $departments = collect($this->getJson('/api/v1/office/departments')->json('data'))->keyBy('name');

        $this->assertSame(['code', 'infrastructure'], $departments['Technique']['tags']);
        $this->assertSame(['finance', 'documents', 'calme'], $departments['Administration et finances']['tags']);
        $this->assertCount(8, $departments);
    }

    public function test_there_is_no_current_plan_at_first(): void
    {
        $this->getJson('/api/v1/office/plan')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_the_ceo_saves_a_plan_that_takes_effect_at_once(): void
    {
        $this->putJson('/api/v1/office/plan', ['layout' => $this->layout()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.layout.rooms.0.department', $this->hr);

        $this->putJson('/api/v1/office/plan', ['layout' => $this->layout(12)])->assertCreated()->assertJsonPath('data.version', 2);

        $this->getJson('/api/v1/office/plan')->assertJsonPath('data.version', 2)->assertJsonPath('data.layout.rooms.2.x', 12);
        $this->assertSame(OfficePlanStatus::Superseded, $this->company->officePlans()->where('version', 1)->sole()->status);
    }

    /**
     * Règle centrale (D-010) : deux départements ne partagent jamais une salle.
     */
    public function test_an_invalid_plan_is_refused_with_the_same_messages_as_the_application(): void
    {
        $layout = $this->layout();
        $layout['rooms'][1]['x'] = 2;

        $this->putJson('/api/v1/office/plan', ['layout' => $layout])
            ->assertUnprocessable()
            ->assertJsonPath('errors.layout.0', 'Bureau individuel et Bureau individuel se chevauchent : deux départements ne partagent jamais une salle.');

        $this->assertSame(0, $this->company->officePlans()->count());
    }

    public function test_check_lists_the_problems_without_saving(): void
    {
        $layout = $this->layout();
        unset($layout['rooms'][1]);
        $layout['rooms'] = array_values($layout['rooms']);

        $this->postJson('/api/v1/office/plan/check', ['layout' => $layout])
            ->assertOk()
            ->assertExactJson(['data' => ['problems' => ["Moyens généraux n'a aucun poste de travail."]]]);
        $this->assertSame(0, $this->company->officePlans()->count());
    }

    public function test_the_shape_of_a_plan_is_validated(): void
    {
        $this->putJson('/api/v1/office/plan', ['layout' => ['cols' => 0, 'rows' => 5000, 'rooms' => [['id' => 'a']]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['layout.cols', 'layout.rows', 'layout.rooms.0.type', 'layout.rooms.0.x']);
    }

    /**
     * D-012 : le CEO accepte une proposition ; elle devient le plan en vigueur.
     */
    public function test_the_ceo_accepts_a_proposal(): void
    {
        $this->putJson('/api/v1/office/plan', ['layout' => $this->layout()])->assertCreated();
        $proposal = $this->propose(12);

        $this->getJson('/api/v1/office/plans?status=proposed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'Nouvelle embauche');

        $this->postJson("/api/v1/office/plans/{$proposal->id}/accept", ['comment' => 'Parfait'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.decision_comment', 'Parfait');

        $this->getJson('/api/v1/office/plan')->assertJsonPath('data.id', $proposal->id);
        $this->assertSame(1, $this->company->officePlans()->where('status', OfficePlanStatus::Superseded)->count());

        $this->postJson("/api/v1/office/plans/{$proposal->id}/accept")->assertConflict();
    }

    public function test_the_ceo_rejects_a_proposal(): void
    {
        $proposal = $this->propose();

        $this->postJson("/api/v1/office/plans/{$proposal->id}/reject", ['comment' => 'Trop serré'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->getJson('/api/v1/office/plan')->assertExactJson(['data' => null]);
        $this->postJson("/api/v1/office/plans/{$proposal->id}/reject")->assertConflict();
    }

    /**
     * D-012 : une proposition en attente devient périmée quand le CEO change le plan.
     */
    public function test_a_pending_proposal_becomes_stale_when_the_ceo_changes_the_plan(): void
    {
        $proposal = $this->propose();
        $other = $this->propose(14);

        $this->putJson('/api/v1/office/plan', ['layout' => $this->layout(12)])->assertCreated();

        $this->assertSame(OfficePlanStatus::Stale, $proposal->refresh()->status);
        $this->postJson("/api/v1/office/plans/{$other->id}/accept")->assertConflict();
    }

    public function test_accepting_one_proposal_makes_the_others_stale(): void
    {
        $first = $this->propose();
        $second = $this->propose(14);

        $this->postJson("/api/v1/office/plans/{$first->id}/accept")->assertOk();

        $this->assertSame(OfficePlanStatus::Stale, $second->refresh()->status);
    }

    /**
     * L'organisation a changé depuis la proposition : elle est revalidée.
     */
    public function test_a_proposal_no_longer_valid_cannot_be_accepted(): void
    {
        $proposal = $this->propose();
        $this->company->positions()->create(['unit_id' => (int) $this->hr, 'title' => 'Recruteur', 'is_head' => false, 'status' => LifecycleStatus::Draft]);

        $this->postJson("/api/v1/office/plans/{$proposal->id}/accept")
            ->assertUnprocessable()
            ->assertJsonPath('errors.layout.0', 'Ressources humaines : 1 postes pour 2 personnes.');
        $this->assertSame(OfficePlanStatus::Proposed, $proposal->refresh()->status);
    }

    /**
     * D-012 : seul le CEO décide ; un agent ne peut ni accepter, ni refuser, ni dessiner.
     */
    public function test_an_agent_cannot_decide_the_layout(): void
    {
        $proposal = $this->propose();
        $agent = Agent::query()->sole();
        $headers = ['X-LeadDesk-Agent' => (string) $agent->id];

        $this->postJson("/api/v1/office/plans/{$proposal->id}/accept", [], $headers)->assertForbidden();
        $this->postJson("/api/v1/office/plans/{$proposal->id}/reject", [], $headers)->assertForbidden();
        $this->putJson('/api/v1/office/plan', ['layout' => $this->layout()], $headers)->assertForbidden();
        $this->getJson('/api/v1/office/plan', $headers)->assertOk();
    }

    public function test_plans_of_another_company_are_unreachable(): void
    {
        $proposal = $this->propose();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/company', ['name' => 'Autre', 'template' => 'blank'])->assertCreated();

        $this->getJson("/api/v1/office/plans/{$proposal->id}")->assertNotFound();
        $this->postJson("/api/v1/office/plans/{$proposal->id}/accept")->assertNotFound();
        $this->getJson('/api/v1/office/plans')->assertJsonCount(0, 'data');
    }

    /**
     * RM-34 : décisions tracées, sans recopier le plan dans le journal.
     */
    public function test_rm34_plan_changes_are_audited_without_the_layout(): void
    {
        $proposal = $this->propose();
        $this->postJson("/api/v1/office/plans/{$proposal->id}/accept")->assertOk();

        $entries = $this->company->auditEntries()->where('event', 'like', 'office_plan.%')->orderBy('id')->get();

        $this->assertSame(['office_plan.created', 'office_plan.updated'], $entries->pluck('event')->all());
        $this->assertArrayNotHasKey('layout', $entries[0]->properties ?? []);
    }

    public function test_the_database_allows_a_single_plan_in_effect(): void
    {
        $this->putJson('/api/v1/office/plan', ['layout' => $this->layout()])->assertCreated();

        $this->expectException(QueryException::class);
        $this->company->officePlans()->create(['version' => 9, 'status' => OfficePlanStatus::Accepted, 'layout' => $this->layout()]);
    }
}
