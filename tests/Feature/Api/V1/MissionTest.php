<?php

namespace Tests\Feature\Api\V1;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Position;
use App\Models\Unit;
use App\Models\User;
use App\Support\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MissionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Agent $coo;

    private Agent $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $direction = Unit::factory()->for($this->company)->create();
        $head = Position::factory()->in($direction)->create(['is_head' => true, 'reports_to_id' => null]);
        $this->coo = Agent::factory()->for($this->company)->active()->create(['position_id' => $head->id]);
        $this->member = Agent::factory()->for($this->company)->active()->create([
            'position_id' => Position::factory()->in($direction)->create(['reports_to_id' => $head->id])->id,
        ]);

        Sanctum::actingAs($this->company->user);
    }

    /**
     * @return array<string, string>
     */
    private function as(Agent $agent): array
    {
        return ['X-LeadDesk-Agent' => (string) $agent->id];
    }

    /**
     * RM-34 : la mission et chacune de ses étapes sont tracées.
     */
    public function test_rm34_the_ceo_gives_a_mission_to_the_head_position(): void
    {
        $id = $this->postJson('/api/v1/missions', ['brief' => 'Lancer la page contact.'])
            ->assertCreated()
            ->assertJsonPath('data.agent_id', $this->coo->id)
            ->assertJsonPath('data.status', 'received')
            ->json('data.id');

        $activity = Activity::factory()->ofType(ActivityType::factory()->for($this->company)->create())->create();

        $this->postJson("/api/v1/missions/{$id}/activity", ['activity_id' => $activity->id], $this->as($this->coo))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.activity_id', $activity->id);

        $this->postJson("/api/v1/missions/{$id}/complete", ['outcome' => 'Page en ligne.'], $this->as($this->coo))
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->postJson("/api/v1/missions/{$id}/cancel")->assertConflict();

        $this->getJson('/api/v1/missions?status=done')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(3, AuditEntry::query()->where('subject_type', 'App\\Models\\Mission')->count());
    }

    public function test_only_the_ceo_gives_or_cancels_and_only_the_recipient_advances(): void
    {
        $this->postJson('/api/v1/missions', ['brief' => 'x'], $this->as($this->coo))->assertForbidden();

        $id = $this->postJson('/api/v1/missions', ['brief' => 'x'])->json('data.id');

        $this->postJson("/api/v1/missions/{$id}/complete", ['outcome' => 'y'], $this->as($this->member))->assertForbidden();
        $this->postJson("/api/v1/missions/{$id}/cancel", [], $this->as($this->coo))->assertForbidden();
        $this->postJson("/api/v1/missions/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    /**
     * RM-03 : le poste qui reçoit les missions est un réglage, pas une valeur en dur.
     */
    public function test_rm03_the_mission_position_is_a_setting(): void
    {
        $this->patchJson('/api/v1/company/settings', ['mission_position_id' => $this->member->position_id])
            ->assertOk()
            ->assertJsonPath('data.mission_recipient.agent_id', $this->member->id);

        $this->postJson('/api/v1/missions', ['brief' => 'x'])->assertJsonPath('data.agent_id', $this->member->id);

        $foreign = Position::factory()->create();
        $this->patchJson('/api/v1/company/settings', ['mission_position_id' => $foreign->id])
            ->assertUnprocessable();
    }

    public function test_a_mission_needs_an_active_recipient(): void
    {
        $this->coo->update(['status' => 'draft']);

        $this->postJson('/api/v1/missions', ['brief' => 'x'])->assertConflict();
    }

    /**
     * D-017 : dans le modèle studio, les missions vont au poste COO.
     */
    public function test_the_studio_coo_receives_missions(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/company', ['name' => 'Studio', 'template' => 'studio'])->assertCreated();

        $company = $user->company()->firstOrFail();
        $this->assertSame('COO', CompanySettings::missionPosition($company)?->title);
    }

    public function test_missions_are_isolated_between_companies(): void
    {
        $id = $this->postJson('/api/v1/missions', ['brief' => 'x'])->json('data.id');

        Sanctum::actingAs(Company::factory()->create()->user);

        $this->getJson("/api/v1/missions/{$id}")->assertNotFound();
        $this->postJson("/api/v1/missions/{$id}/cancel")->assertNotFound();
        $this->getJson('/api/v1/missions')->assertJsonCount(0, 'data');
    }
}
