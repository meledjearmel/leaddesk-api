<?php

namespace Tests\Feature\Api\V1;

use App\Models\Agent;
use App\Models\Company;
use App\Support\CompanySettings;
use App\Support\OfficeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Sanctum::actingAs($this->company->user);
    }

    public function test_routes_require_a_token(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/company/settings')->assertUnauthorized();
        $this->patchJson('/api/v1/company/settings', [])->assertUnauthorized();
    }

    /**
     * RM-50 : par défaut, une journée simulée de 24 h dure 1 heure réelle.
     */
    public function test_rm50_a_simulated_day_lasts_one_real_hour_by_default(): void
    {
        $this->getJson('/api/v1/company/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.simulated_day_minutes', 60)
            ->assertJsonPath('data.defaults.simulated_day_minutes', 60)
            ->assertJsonPath('data.clock.speed', 24);
    }

    /**
     * RM-50 : le rapport n'est pas codé en dur, la valeur par défaut est un réglage.
     */
    public function test_rm50_the_default_comes_from_configuration(): void
    {
        config(['leaddesk.company_settings.simulated_day_minutes' => 120]);

        $this->getJson('/api/v1/company/settings')
            ->assertJsonPath('data.settings.simulated_day_minutes', 120)
            ->assertJsonPath('data.clock.speed', 12);
    }

    /**
     * RM-50 : le CEO règle le rapport, puis peut revenir à la valeur par défaut.
     */
    public function test_rm50_the_ceo_sets_the_simulated_day_length(): void
    {
        $this->patchJson('/api/v1/company/settings', ['simulated_day_minutes' => 30])
            ->assertOk()
            ->assertJsonPath('data.settings.simulated_day_minutes', 30)
            ->assertJsonPath('data.clock.speed', 48);

        $this->assertSame(['simulated_day_minutes' => 30], $this->company->fresh()?->settings);
        $this->assertSame(['simulated_day_minutes' => 30, 'mission_position_id' => null], CompanySettings::for($this->company->fresh() ?? $this->company));
        $this->assertDatabaseHas('audit_entries', ['company_id' => $this->company->id, 'subject_type' => Company::class, 'event' => 'company.updated']);

        $this->patchJson('/api/v1/company/settings', ['simulated_day_minutes' => null])
            ->assertOk()
            ->assertJsonPath('data.settings.simulated_day_minutes', 60);

        $this->assertNull($this->company->fresh()?->settings);
    }

    public function test_the_simulated_day_length_is_bounded(): void
    {
        $this->patchJson('/api/v1/company/settings', ['simulated_day_minutes' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('simulated_day_minutes');

        $this->patchJson('/api/v1/company/settings', ['simulated_day_minutes' => 5000])
            ->assertUnprocessable();
    }

    public function test_an_agent_cannot_change_the_settings(): void
    {
        $agent = Agent::factory()->for($this->company)->active()->create();

        $this->patchJson('/api/v1/company/settings', ['simulated_day_minutes' => 30], ['X-LeadDesk-Agent' => (string) $agent->id])
            ->assertForbidden();

        $this->assertNull($this->company->fresh()?->settings);
    }

    /**
     * RM-51 : le temps simulé se déduit du temps réel, sans rien modifier ;
     * deux ordinateurs voient la même heure simulée.
     */
    public function test_rm51_the_simulated_clock_is_derived_from_real_time(): void
    {
        $now = Carbon::create(2026, 11, 15, 12, 0, 0, 'UTC');
        $this->assertNotNull($now);

        // 60 minutes par journée : 2 min 30 s réelles = 1 h simulée.
        $clock = OfficeClock::at($this->company, $now);
        $later = OfficeClock::at($this->company, $now->copy()->addSeconds(150));

        $this->assertSame(3600, ($later['simulated_seconds'] - $clock['simulated_seconds'] + 86400) % 86400);
        $this->assertSame($clock, OfficeClock::at($this->company, $now->copy()));
        $this->assertSame($now->toIso8601String(), $clock['real_time']);
    }

    public function test_settings_are_isolated_between_companies(): void
    {
        $other = Company::factory()->create(['settings' => ['simulated_day_minutes' => 10]]);

        $this->getJson('/api/v1/company/settings')->assertJsonPath('data.settings.simulated_day_minutes', 60);

        $this->patchJson('/api/v1/company/settings', ['simulated_day_minutes' => 90])->assertOk();

        $this->assertSame(['simulated_day_minutes' => 10], $other->fresh()?->settings);
    }
}
