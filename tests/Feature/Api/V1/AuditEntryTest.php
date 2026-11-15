<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ActorType;
use App\Enums\AuditKind;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Unit;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RM-34 : consultations, actions et changements des agents sont enregistrés.
 */
class AuditEntryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Sanctum::actingAs($this->company->user);
    }

    public function test_rm34_the_ceo_reads_the_log_newest_first(): void
    {
        $this->postJson('/api/v1/units', ['name' => 'Produit'])->assertCreated();
        $this->postJson('/api/v1/agents', ['name' => 'Lina'])->assertCreated();

        $this->getJson('/api/v1/audit-entries')
            ->assertOk()
            ->assertJsonPath('data.0.event', 'agent.created')
            ->assertJsonPath('data.0.actor_type', 'ceo')
            ->assertJsonPath('data.0.actor_id', $this->company->user_id)
            ->assertJsonPath('data.0.kind', 'change')
            ->assertJsonPath('data.1.event', 'unit.created')
            ->assertJsonPath('data.2.event', 'company.created');
    }

    public function test_the_log_is_filtered_by_event_subject_kind_and_actor(): void
    {
        $unit = Unit::factory()->for($this->company)->create();
        $agent = Agent::factory()->for($this->company)->create();
        $audit = app(Audit::class);

        $audit->actingAs(ActorType::Agent, $agent->id, fn () => $audit->record(
            $this->company,
            'unit.viewed',
            $unit,
            kind: AuditKind::Consultation,
        ));

        $this->getJson('/api/v1/audit-entries?event=unit.viewed')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/audit-entries?event=unit.')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/audit-entries?subject=agent')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/audit-entries?subject=unit&subject_id={$unit->id}")->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/audit-entries?kind=consultation')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor_type', 'agent')
            ->assertJsonPath('data.0.actor_id', $agent->id);
        $this->getJson('/api/v1/audit-entries?actor_type=agent')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/audit-entries?kind=inconnu')->assertUnprocessable();
        $this->getJson('/api/v1/audit-entries?event=unit%25')->assertUnprocessable();

        AiProvider::factory()->for($this->company)->create();
        $this->getJson('/api/v1/audit-entries?subject=ai_provider')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'ai_provider.created');
    }

    public function test_the_author_is_restored_after_acting_as_an_agent(): void
    {
        $audit = app(Audit::class);

        $audit->actingAs(ActorType::Agent, 7, fn () => null);
        $entry = $audit->record($this->company, 'test.after');

        $this->assertSame(ActorType::Ceo, $entry->actor_type);
    }

    public function test_the_log_of_another_company_is_never_shown(): void
    {
        Unit::factory()->create();

        $events = $this->getJson('/api/v1/audit-entries')->json('data.*.event');

        $this->assertSame(['company.created'], $events);
        $this->assertGreaterThan(1, AuditEntry::query()->count());
    }

    public function test_the_log_is_paginated(): void
    {
        Unit::factory()->for($this->company)->count(60)->create();

        $this->getJson('/api/v1/audit-entries')->assertJsonCount(50, 'data');
        $this->getJson('/api/v1/audit-entries?page=2')->assertJsonCount(11, 'data');
    }

    public function test_the_log_cannot_be_written_or_erased_through_the_api(): void
    {
        $id = AuditEntry::query()->sole()->id;

        $this->postJson('/api/v1/audit-entries', ['event' => 'faux'])->assertMethodNotAllowed();
        $this->deleteJson("/api/v1/audit-entries/{$id}")->assertNotFound();
        $this->patchJson("/api/v1/audit-entries/{$id}", ['event' => 'faux'])->assertNotFound();

        $this->assertSame(1, AuditEntry::query()->count());
    }

    public function test_hidden_attributes_never_reach_the_log(): void
    {
        $entries = AuditEntry::query()->get();

        foreach ($entries as $entry) {
            $this->assertArrayNotHasKey('password', $entry->properties ?? []);
            $this->assertArrayNotHasKey('remember_token', $entry->properties ?? []);
        }

        $this->assertNotEmpty($entries);
    }
}
