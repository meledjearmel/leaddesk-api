<?php

namespace Tests\Feature\Mcp;

use App\Enums\DecisionStatus;
use App\Enums\LifecycleStatus;
use App\Enums\OfficePlanStatus;
use App\Mcp\Servers\LeadDeskServer;
use App\Mcp\Tools\OfficeContextTool;
use App\Mcp\Tools\OfficeProposePlanTool;
use App\Models\Agent;
use App\Models\Company;
use App\Models\Decision;
use App\Models\User;
use App\Support\ActingAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * MCP interne : outils d'aménagement (D-012, D-038).
 */
class OfficeToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Agent $facilities;

    private Agent $recruiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/company', ['name' => 'Atelier Nord', 'template' => 'blank'])->assertCreated();
        $this->company = Company::query()->sole();

        $this->facilities = $this->agentIn('facilities', 'Lisandre');
        $this->recruiter = $this->agentIn('hr', 'Ysée');
    }

    private function agentIn(string $role, string $name): Agent
    {
        $position = $this->company->positions()
            ->whereIn('unit_id', $this->company->units()->where('system_role', $role)->select('id'))
            ->sole();

        return $this->company->agents()->create([
            'name' => $name,
            'status' => LifecycleStatus::Active,
            'position_id' => $position->id,
        ]);
    }

    private function unitId(string $role): string
    {
        return (string) $this->company->units()->where('system_role', $role)->sole()->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function layout(bool $overlap = false): array
    {
        return [
            'cols' => 20,
            'rows' => 10,
            'rooms' => [
                ['id' => 'rh', 'type' => 'private_office', 'department' => $this->unitId('hr'), 'x' => 0, 'y' => 0, 'cols' => 3, 'rows' => 3],
                ['id' => 'mg', 'type' => 'private_office', 'department' => $this->unitId('facilities'), 'x' => $overlap ? 1 : 4, 'y' => 0, 'cols' => 3, 'rows' => 3],
            ],
        ];
    }

    private function as(Agent $agent): void
    {
        app(ActingAgent::class)->set($agent);
    }

    public function test_context_gives_departments_catalog_rules_and_current_plan(): void
    {
        $this->as($this->facilities);

        LeadDeskServer::actingAs($this->user)
            ->tool(OfficeContextTool::class)
            ->assertOk()
            ->assertSee('Moyens généraux')
            ->assertSee('open_space')
            ->assertSee('Deux départements ne partagent jamais une salle')
            ->assertSee('"pending_proposals":0');
    }

    /**
     * D-012 : le poste des Moyens généraux propose ; la proposition attend le CEO.
     */
    public function test_facilities_proposes_a_plan_that_waits_for_the_ceo(): void
    {
        $this->as($this->facilities);

        LeadDeskServer::actingAs($this->user)
            ->tool(OfficeProposePlanTool::class, ['layout' => $this->layout(), 'reason' => 'Premier aménagement'])
            ->assertOk()
            ->assertSee('elle attend la décision du CEO');

        $plan = $this->company->officePlans()->sole();
        $this->assertSame(OfficePlanStatus::Proposed, $plan->status);
        $this->assertSame($this->facilities->id, $plan->proposed_by_agent_id);
        $this->assertSame('Premier aménagement', $plan->reason);

        $decision = $this->company->decisions()->sole();
        $this->assertSame(Decision::KIND_OFFICE_PLAN, $decision->kind);
        $this->assertSame(DecisionStatus::Pending, $decision->status);
        $this->assertSame($this->facilities->id, $decision->requested_by_agent_id);

        // Rien ne change avant la décision du CEO.
        $this->getJson('/api/v1/office/plan')->assertExactJson(['data' => null]);
    }

    /**
     * Le plan proposé passe par les mêmes règles : invalide, il est refusé.
     */
    public function test_an_invalid_proposal_is_refused_with_its_problems(): void
    {
        $this->as($this->facilities);

        LeadDeskServer::actingAs($this->user)
            ->tool(OfficeProposePlanTool::class, ['layout' => $this->layout(overlap: true), 'reason' => 'Essai'])
            ->assertHasErrors(['deux départements ne partagent jamais une salle']);

        $this->assertSame(0, $this->company->officePlans()->count());
        $this->assertSame(0, $this->company->decisions()->count());
    }

    /**
     * D-012 et RM-03 : l'outil est donné au poste, pas à tous les agents.
     */
    public function test_a_position_without_the_tool_cannot_propose(): void
    {
        $this->as($this->recruiter);

        LeadDeskServer::actingAs($this->user)
            ->tool(OfficeProposePlanTool::class, ['layout' => $this->layout(), 'reason' => 'Essai'])
            ->assertHasErrors(["n'a pas reçu l'outil office.propose_plan"]);

        $this->recruiter->position?->update(['tool_permissions' => ['office.propose_plan' => 'allow']]);
        $this->recruiter->refresh();
        $this->as($this->recruiter);

        LeadDeskServer::actingAs($this->user)
            ->tool(OfficeProposePlanTool::class, ['layout' => $this->layout(), 'reason' => 'Essai'])
            ->assertOk();
    }

    public function test_the_ceo_does_not_propose_through_the_mcp(): void
    {
        LeadDeskServer::actingAs($this->user)
            ->tool(OfficeProposePlanTool::class, ['layout' => $this->layout(), 'reason' => 'Essai'])
            ->assertHasErrors(['Seul un agent propose un aménagement']);
    }

    /**
     * Les décisions du CEO, par le plan ou par la décision, restent synchronisées.
     */
    public function test_approving_the_decision_puts_the_plan_in_effect(): void
    {
        $this->as($this->facilities);
        LeadDeskServer::actingAs($this->user)
            ->tool(OfficeProposePlanTool::class, ['layout' => $this->layout(), 'reason' => 'Premier aménagement'])
            ->assertOk();
        app(ActingAgent::class)->clear();

        $decision = $this->company->decisions()->sole();
        $this->postJson("/api/v1/decisions/{$decision->id}/approve", ['note' => 'Bien'])->assertOk();

        $this->getJson('/api/v1/office/plan')->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.decision_comment', 'Bien');
    }

    public function test_accepting_the_plan_closes_the_decision_and_stale_ones_are_closed_too(): void
    {
        $this->as($this->facilities);
        $server = LeadDeskServer::actingAs($this->user);
        $server->tool(OfficeProposePlanTool::class, ['layout' => $this->layout(), 'reason' => 'A'])->assertOk();
        $server->tool(OfficeProposePlanTool::class, ['layout' => $this->layout(), 'reason' => 'B'])->assertOk();
        app(ActingAgent::class)->clear();

        [$first, $second] = $this->company->officePlans()->orderBy('version')->get()->all();
        $this->postJson("/api/v1/office/plans/{$first->id}/accept")->assertOk();

        $decisions = $this->company->decisions()->orderBy('id')->get();
        $this->assertSame(DecisionStatus::Approved, $decisions[0]->status);
        $this->assertSame(DecisionStatus::Rejected, $decisions[1]->status);
        $this->assertStringContainsString('Périmée', (string) $decisions[1]->decision_note);
        $this->assertSame(OfficePlanStatus::Stale, $second->refresh()->status);
    }

    public function test_rejecting_the_decision_rejects_the_plan(): void
    {
        $this->as($this->facilities);
        LeadDeskServer::actingAs($this->user)
            ->tool(OfficeProposePlanTool::class, ['layout' => $this->layout(), 'reason' => 'A'])
            ->assertOk();
        app(ActingAgent::class)->clear();

        $decision = $this->company->decisions()->sole();
        $this->postJson("/api/v1/decisions/{$decision->id}/reject")->assertOk();

        $this->assertSame(OfficePlanStatus::Rejected, $this->company->officePlans()->sole()->status);
    }

    /**
     * Route HTTP réelle : jeton du CEO, agent dans l'en-tête, journal à son nom.
     */
    public function test_the_http_endpoint_acts_as_the_agent_named_in_the_header(): void
    {
        $token = $this->user->createToken('poste')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-LeadDesk-Agent' => (string) $this->facilities->id,
        ])->postJson('/mcp/leaddesk', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'office.propose_plan',
                'arguments' => ['layout' => $this->layout(), 'reason' => 'Par HTTP'],
            ],
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'));
        $this->assertSame($this->facilities->id, $this->company->officePlans()->sole()->proposed_by_agent_id);
        $this->assertTrue($this->company->auditEntries()->where('event', 'office_plan.created')->where('actor_id', $this->facilities->id)->exists());
    }

    public function test_the_http_endpoint_requires_a_token(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/mcp/leaddesk', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    /**
     * D-038 : dans le modèle studio, seul le responsable des Moyens généraux a l'outil.
     */
    public function test_in_the_studio_only_the_facilities_head_has_the_tool(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/company', ['name' => 'Studio', 'template' => 'studio'])->assertCreated();
        $studio = Company::query()->where('name', 'Studio')->sole();

        $holders = $studio->positions()->get()
            ->filter(fn ($position) => ($position->tool_permissions['office.propose_plan'] ?? null) === 'allow')
            ->pluck('title')
            ->all();

        $this->assertSame(['Responsable Moyens généraux'], array_values($holders));
    }
}
