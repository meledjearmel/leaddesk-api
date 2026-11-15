<?php

namespace Tests\Feature\Api\V1;

use App\Enums\RuleRelation;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Decision;
use App\Models\Rule;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 3 : gouvernance des règles et décisions du CEO.
 */
class RuleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Unit $department;

    private Unit $pole;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->department = Unit::factory()->for($this->company)->create(['name' => 'Technique']);
        $this->pole = Unit::factory()->childOf($this->department)->create(['name' => 'Développement']);
        $this->agent = Agent::factory()->for($this->company)->active()->create(['name' => 'Maëlle']);

        Sanctum::actingAs($this->company->user);
    }

    /**
     * @return array<string, string>
     */
    private function asAgent(): array
    {
        return ['X-LeadDesk-Agent' => (string) $this->agent->id];
    }

    private function rule(?Unit $unit = null, string $title = 'Toute livraison est relue'): Rule
    {
        return Rule::factory()->for($this->company)->create(['unit_id' => $unit?->id, 'title' => $title]);
    }

    /**
     * RM-24 : les règles suivent la hiérarchie et s'appliquent vers les
     * niveaux inférieurs.
     */
    public function test_rm24_rules_are_inherited_downwards_only(): void
    {
        $other = Unit::factory()->for($this->company)->create();
        $companyRule = $this->rule();
        $departmentRule = $this->rule($this->department, 'Revue de code obligatoire');
        $poleRule = $this->rule($this->pole, 'Tests avant fusion');
        $this->rule($other, 'Règle d\'un autre département');

        $this->getJson('/api/v1/rules')->assertJsonCount(4, 'data');

        $this->assertSame(
            [$companyRule->id, $departmentRule->id, $poleRule->id],
            $this->getJson("/api/v1/rules?unit_id={$this->pole->id}")->json('data.*.id'),
        );

        // Le département n'hérite pas de la règle de son pôle.
        $this->assertSame(
            [$companyRule->id, $departmentRule->id],
            $this->getJson("/api/v1/rules?unit_id={$this->department->id}")->json('data.*.id'),
        );
    }

    /**
     * RM-24 : un niveau inférieur peut préciser ou renforcer une règle
     * héritée ; il n'existe aucun moyen de l'assouplir.
     */
    public function test_rm24_a_lower_level_only_precises_or_reinforces_an_inherited_rule(): void
    {
        $inherited = $this->rule($this->department, 'Revue de code obligatoire');

        $this->postJson('/api/v1/rules', [
            'unit_id' => $this->pole->id,
            'parent_rule_id' => $inherited->id,
            'relation' => 'reinforce',
            'title' => 'Deux relecteurs',
            'body' => 'Chaque changement est relu par deux personnes.',
        ])->assertCreated()
            ->assertJsonPath('data.parent_rule_id', $inherited->id)
            ->assertJsonPath('data.relation', 'reinforce');

        foreach (['relax', 'assouplir', 'remove', 'replace'] as $forbidden) {
            $this->postJson('/api/v1/rules', [
                'unit_id' => $this->pole->id,
                'parent_rule_id' => $inherited->id,
                'relation' => $forbidden,
                'title' => 'Sans revue',
                'body' => 'Pas de revue pour les petits changements.',
            ])->assertUnprocessable()->assertJsonValidationErrors('relation');
        }

        $this->assertSame(['precise', 'reinforce'], array_column(RuleRelation::cases(), 'value'));

        // Se rattacher à une règle héritée demande de dire comment.
        $this->postJson('/api/v1/rules', [
            'unit_id' => $this->pole->id,
            'parent_rule_id' => $inherited->id,
            'title' => 'x',
            'body' => 'y',
        ])->assertUnprocessable()->assertJsonValidationErrors('relation');
    }

    /**
     * RM-24 : la règle précisée doit venir d'un niveau strictement supérieur.
     */
    public function test_rm24_the_refined_rule_must_come_from_a_higher_level(): void
    {
        $poleRule = $this->rule($this->pole);
        $sibling = Unit::factory()->for($this->company)->create();
        $siblingRule = $this->rule($sibling);
        $payload = ['relation' => 'precise', 'title' => 'x', 'body' => 'y'];

        // Même niveau, niveau inférieur, autre branche, ou règle d'entreprise sans unité.
        $this->postJson('/api/v1/rules', [...$payload, 'unit_id' => $this->pole->id, 'parent_rule_id' => $poleRule->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_rule_id');
        $this->postJson('/api/v1/rules', [...$payload, 'unit_id' => $this->department->id, 'parent_rule_id' => $poleRule->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_rule_id');
        $this->postJson('/api/v1/rules', [...$payload, 'unit_id' => $this->pole->id, 'parent_rule_id' => $siblingRule->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_rule_id');
        $this->postJson('/api/v1/rules', [...$payload, 'parent_rule_id' => $poleRule->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_rule_id');
    }

    /**
     * RM-24 : une règle que des niveaux inférieurs précisent ne se retire pas
     * sous eux.
     */
    public function test_rm24_a_refined_rule_cannot_be_removed(): void
    {
        $inherited = $this->rule($this->department);
        Rule::factory()->for($this->company)->create([
            'unit_id' => $this->pole->id,
            'parent_rule_id' => $inherited->id,
            'relation' => 'precise',
        ]);

        $this->deleteJson("/api/v1/rules/{$inherited->id}")->assertConflict();
        $this->assertNotSoftDeleted($inherited);
    }

    /**
     * RM-25 : seul le CEO humain décide des changements de règles. Aucun
     * agent, CEO virtuel compris, ne crée, ne modifie ni ne retire une règle.
     */
    public function test_rm25_no_agent_can_change_a_rule(): void
    {
        $rule = $this->rule();

        $this->postJson('/api/v1/rules', ['title' => 'x', 'body' => 'y'], $this->asAgent())->assertForbidden();
        $this->patchJson("/api/v1/rules/{$rule->id}", ['body' => 'Assouplie.'], $this->asAgent())->assertForbidden();
        $this->deleteJson("/api/v1/rules/{$rule->id}", [], $this->asAgent())->assertForbidden();
        $this->postJson("/api/v1/rules/{$rule->id}/extend-to-running", [], $this->asAgent())->assertForbidden();

        $this->assertSame(1, Rule::query()->count());
        $this->assertSame('Texte de la règle.', $rule->fresh()?->body);

        // Un agent peut lire les règles.
        $this->getJson('/api/v1/rules', $this->asAgent())->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * RM-25 : aucun agent ne décide à la place du CEO.
     */
    public function test_rm25_no_agent_can_decide_in_place_of_the_ceo(): void
    {
        $decision = $this->postJson('/api/v1/rule-proposals', [
            'operation' => 'create',
            'title' => 'Nouvelle règle',
            'body' => 'Texte.',
            'problem' => 'Trop de retours en correction.',
        ], $this->asAgent())->assertCreated()->json('data.id');

        $this->postJson("/api/v1/decisions/{$decision}/approve", [], $this->asAgent())->assertForbidden();
        $this->postJson("/api/v1/decisions/{$decision}/reject", [], $this->asAgent())->assertForbidden();
        $this->postJson("/api/v1/decisions/{$decision}/request-info", ['note' => '?'], $this->asAgent())->assertForbidden();

        $this->assertSame('pending', Decision::query()->sole()->status->value);
        $this->assertSame(0, Rule::query()->count());
    }

    /**
     * RM-28 : une proposition présente le problème, la règle concernée, le
     * changement demandé, les personnes touchées, les effets et les risques.
     * RM-26 : la règle existante reste en vigueur jusqu'à la décision du CEO.
     */
    public function test_rm26_rm28_a_proposal_waits_for_the_ceo_and_changes_nothing(): void
    {
        $rule = $this->rule($this->department, 'Revue de code obligatoire');

        $id = $this->postJson('/api/v1/rule-proposals', [
            'operation' => 'update',
            'rule_id' => $rule->id,
            'body' => 'Revue obligatoire, sauf correctifs urgents.',
            'problem' => 'Les correctifs urgents attendent trop longtemps.',
            'affected' => 'Pôles Développement et Maintenance.',
            'expected_effects' => 'Correctifs livrés plus vite.',
            'risks' => 'Un défaut peut passer sans relecture.',
        ], $this->asAgent())->assertCreated()
            ->assertJsonPath('data.kind', 'rule_change')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.title', 'Modifier la règle : Revue de code obligatoire')
            ->assertJsonPath('data.requested_by_agent_id', $this->agent->id)
            ->assertJsonPath('data.requested_by_name', 'Maëlle')
            ->assertJsonPath('data.subject_id', $rule->id)
            ->assertJsonPath('data.payload.problem', 'Les correctifs urgents attendent trop longtemps.')
            ->assertJsonPath('data.payload.risks', 'Un défaut peut passer sans relecture.')
            ->assertJsonPath('data.payload.change.rule_id', $rule->id)
            ->json('data.id');

        // Elle arrive dans les décisions en attente du CEO…
        $this->getJson('/api/v1/decisions?status=pending')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        // … et la règle n'a pas bougé.
        $this->assertSame('Texte de la règle.', $rule->fresh()?->body);
        $this->assertSame(1, $rule->fresh()?->version);

        // Une proposition sans problème exposé est refusée.
        $this->postJson('/api/v1/rule-proposals', ['operation' => 'archive', 'rule_id' => $rule->id], $this->asAgent())
            ->assertUnprocessable()->assertJsonValidationErrors('problem');
    }

    public function test_the_ceo_approves_a_proposal_and_the_rule_changes(): void
    {
        $rule = $this->rule();
        $id = $this->postJson('/api/v1/rule-proposals', [
            'operation' => 'update',
            'rule_id' => $rule->id,
            'title' => 'Toute livraison est relue deux fois',
            'problem' => 'Des défauts passent.',
        ], $this->asAgent())->json('data.id');

        $this->postJson("/api/v1/decisions/{$id}/approve", ['note' => 'D\'accord.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decision_note', 'D\'accord.');

        $fresh = $rule->fresh();
        $this->assertSame('Toute livraison est relue deux fois', $fresh?->title);
        $this->assertSame('Texte de la règle.', $fresh?->body);
        $this->assertSame(2, $fresh?->version);

        // Une décision prise ne se reprend pas par la même route.
        $this->postJson("/api/v1/decisions/{$id}/approve")->assertConflict();
        $this->postJson("/api/v1/decisions/{$id}/reject")->assertConflict();

        // RM-34 : la décision est tracée au nom du CEO.
        $entry = AuditEntry::query()->where('event', 'decision.updated')->latest('id')->firstOrFail();
        $this->assertSame('ceo', $entry->actor_type->value);
    }

    public function test_the_ceo_rejects_a_proposal_and_nothing_changes(): void
    {
        $rule = $this->rule();
        $id = $this->postJson('/api/v1/rule-proposals', [
            'operation' => 'archive',
            'rule_id' => $rule->id,
            'problem' => 'Règle jugée inutile.',
        ], $this->asAgent())->json('data.id');

        $this->postJson("/api/v1/decisions/{$id}/reject", ['note' => 'Elle reste.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertNotSoftDeleted($rule);
        $this->getJson('/api/v1/decisions?status=pending')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/decisions?status=rejected')->assertJsonCount(1, 'data');
    }

    public function test_the_ceo_asks_for_details_and_the_agent_replies(): void
    {
        $id = $this->postJson('/api/v1/rule-proposals', [
            'operation' => 'create',
            'title' => 'Nouvelle règle',
            'body' => 'Texte.',
            'problem' => 'Problème.',
        ], $this->asAgent())->json('data.id');

        $this->postJson("/api/v1/decisions/{$id}/reply", ['message' => 'Trop tôt.'], $this->asAgent())->assertConflict();
        $this->postJson("/api/v1/decisions/{$id}/request-info", [])->assertUnprocessable();

        $this->postJson("/api/v1/decisions/{$id}/request-info", ['note' => 'Qui est concerné ?'])
            ->assertOk()->assertJsonPath('data.status', 'needs_info');

        $this->postJson("/api/v1/decisions/{$id}/reply", ['message' => 'Tout le pôle.'], $this->asAgent())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payload.replies.0.message', 'Tout le pôle.')
            ->assertJsonPath('data.payload.replies.0.agent_id', $this->agent->id);

        $this->postJson("/api/v1/decisions/{$id}/approve")->assertOk();
        $this->assertSame(1, Rule::query()->count());
    }

    public function test_a_proposal_that_no_longer_applies_cannot_be_approved(): void
    {
        $rule = $this->rule();
        $id = $this->postJson('/api/v1/rule-proposals', [
            'operation' => 'update',
            'rule_id' => $rule->id,
            'body' => 'Nouveau texte.',
            'problem' => 'Problème.',
        ], $this->asAgent())->json('data.id');

        $this->deleteJson("/api/v1/rules/{$rule->id}")->assertNoContent();

        $this->postJson("/api/v1/decisions/{$id}/approve")->assertConflict();
        $this->assertSame('pending', Decision::query()->findOrFail($id)->status->value);
    }

    /**
     * RM-27 : une règle approuvée s'applique aux nouvelles activités ;
     * l'étendre aux activités en cours demande une décision explicite du CEO.
     */
    public function test_rm27_a_rule_reaches_running_activities_only_by_explicit_decision(): void
    {
        $type = ActivityType::factory()->for($this->company)->create();
        $running = Activity::factory()->ofType($type)->create();
        $running->units()->sync([$this->pole->id]);

        $this->travel(1)->days();

        $ruleId = $this->postJson('/api/v1/rules', [
            'unit_id' => $this->department->id,
            'title' => 'Compte rendu obligatoire',
            'body' => 'Chaque tâche produit un compte rendu.',
        ])->assertCreated()->assertJsonPath('data.extended_to_running', false)->json('data.id');

        $this->travel(1)->days();

        $new = Activity::factory()->ofType($type)->create();
        $new->units()->sync([$this->pole->id]);

        $this->getJson("/api/v1/activities/{$new->id}/rules")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/activities/{$running->id}/rules")->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/rules/{$ruleId}/extend-to-running")
            ->assertOk()->assertJsonPath('data.extended_to_running', true);

        $this->getJson("/api/v1/activities/{$running->id}/rules")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ruleId);
    }

    /**
     * RM-27 : modifier une règle ne l'étend pas d'office aux activités en
     * cours, même si la version précédente l'était.
     */
    public function test_rm27_a_new_version_starts_again_with_new_activities_only(): void
    {
        $rule = $this->rule();
        $rule->update(['extended_to_running' => true]);

        $this->patchJson("/api/v1/rules/{$rule->id}", ['body' => 'Version 2.'])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.extended_to_running', false);

        $this->patchJson("/api/v1/rules/{$rule->id}", ['body' => 'Version 3.', 'extend_to_running' => true])
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.extended_to_running', true);
    }

    public function test_a_rule_of_another_team_does_not_apply_to_an_activity(): void
    {
        $other = Unit::factory()->for($this->company)->create();
        $this->rule($other);
        $companyRule = $this->rule();

        $this->travel(1)->minutes();

        $activity = Activity::factory()->ofType(ActivityType::factory()->for($this->company)->create())->create();
        $activity->units()->sync([$this->pole->id]);

        $this->assertSame([$companyRule->id], $this->getJson("/api/v1/activities/{$activity->id}/rules")->json('data.*.id'));
    }

    public function test_rules_and_decisions_of_another_company_are_unreachable(): void
    {
        $foreignRule = Rule::factory()->create();
        $foreignUnit = Unit::factory()->create();
        $foreignDecision = Decision::query()->create([
            'company_id' => $foreignRule->company_id,
            'kind' => Decision::KIND_RULE_CHANGE,
            'title' => 'Étrangère',
        ]);

        $this->getJson("/api/v1/rules/{$foreignRule->id}")->assertNotFound();
        $this->patchJson("/api/v1/rules/{$foreignRule->id}", ['body' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/rules/{$foreignRule->id}")->assertNotFound();
        $this->getJson("/api/v1/decisions/{$foreignDecision->id}")->assertNotFound();
        $this->postJson("/api/v1/decisions/{$foreignDecision->id}/approve")->assertNotFound();

        $this->postJson('/api/v1/rules', ['title' => 'x', 'body' => 'y', 'unit_id' => $foreignUnit->id])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $this->postJson('/api/v1/rule-proposals', [
            'operation' => 'archive',
            'rule_id' => $foreignRule->id,
            'problem' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('rule_id');

        $this->getJson('/api/v1/rules')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/decisions')->assertJsonCount(0, 'data');
    }
}
