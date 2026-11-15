<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Position;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Sanctum::actingAs($this->company->user);
    }

    public function test_an_account_without_company_cannot_use_the_organisation(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/units')->assertNotFound();
        $this->postJson('/api/v1/units', ['name' => 'Technique'])->assertNotFound();
    }

    /**
     * RM-03 : l'organisation est configurable, avec des unités imbriquées
     * dont la profondeur n'est pas fixée.
     */
    public function test_rm03_units_nest_to_any_depth(): void
    {
        $department = $this->postJson('/api/v1/units', [
            'name' => 'Technique',
            'kind' => 'Département',
            'color' => '#5B8DEF',
        ])->assertCreated()->assertJsonPath('data.parent_id', null)->json('data.id');

        $pole = $this->postJson('/api/v1/units', [
            'name' => 'Développement',
            'kind' => 'Pôle',
            'parent_id' => $department,
        ])->assertCreated()->assertJsonPath('data.parent_id', $department)->json('data.id');

        $team = $this->postJson('/api/v1/units', [
            'name' => 'Équipe mobile',
            'kind' => 'Équipe',
            'parent_id' => $pole,
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/v1/units')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.id', $team)
            ->assertJsonPath('data.2.parent_id', $pole);
    }

    public function test_creation_validates_its_input(): void
    {
        $this->postJson('/api/v1/units', ['name' => '', 'color' => 'bleu', 'parent_id' => 999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'color', 'parent_id']);
    }

    public function test_a_unit_of_another_company_is_invisible_and_cannot_be_a_parent(): void
    {
        $foreign = Unit::factory()->create();

        $this->getJson("/api/v1/units/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/v1/units/{$foreign->id}", ['name' => 'Piraté'])->assertNotFound();
        $this->deleteJson("/api/v1/units/{$foreign->id}")->assertNotFound();

        $this->postJson('/api/v1/units', ['name' => 'Intrus', 'parent_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->getJson('/api/v1/units')->assertJsonCount(0, 'data');
        $this->assertSame($foreign->name, $foreign->fresh()?->name);
    }

    public function test_a_unit_is_renamed_and_moved(): void
    {
        $a = Unit::factory()->for($this->company)->create();
        $b = Unit::factory()->for($this->company)->create();

        $this->patchJson("/api/v1/units/{$b->id}", ['name' => 'Renommée', 'parent_id' => $a->id])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renommée')
            ->assertJsonPath('data.parent_id', $a->id);

        $this->patchJson("/api/v1/units/{$b->id}", ['parent_id' => null])
            ->assertOk()
            ->assertJsonPath('data.parent_id', null);
    }

    public function test_a_unit_cannot_be_moved_under_itself_or_a_descendant(): void
    {
        $root = Unit::factory()->for($this->company)->create();
        $child = Unit::factory()->childOf($root)->create();
        $grandchild = Unit::factory()->childOf($child)->create();

        $this->patchJson("/api/v1/units/{$root->id}", ['parent_id' => $root->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->patchJson("/api/v1/units/{$root->id}", ['parent_id' => $grandchild->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->assertNull($root->fresh()?->parent_id);
    }

    public function test_a_unit_with_children_or_positions_cannot_be_trashed(): void
    {
        $root = Unit::factory()->for($this->company)->create();
        $child = Unit::factory()->childOf($root)->create();
        Position::factory()->in($child)->create();

        $this->deleteJson("/api/v1/units/{$root->id}")->assertConflict();
        $this->deleteJson("/api/v1/units/{$child->id}")->assertConflict();

        $this->assertNotSoftDeleted($root);
        $this->assertNotSoftDeleted($child);
    }

    public function test_an_empty_unit_goes_to_the_trash_and_comes_back(): void
    {
        $unit = Unit::factory()->for($this->company)->create();

        $this->deleteJson("/api/v1/units/{$unit->id}")->assertNoContent();

        $this->assertSoftDeleted($unit);
        $this->getJson('/api/v1/units')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/units/{$unit->id}")->assertNotFound();
        $this->getJson('/api/v1/units?trashed=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unit->id);

        $this->postJson("/api/v1/units/{$unit->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.deleted_at', null);

        $this->assertNotSoftDeleted($unit);
    }

    public function test_a_unit_cannot_be_restored_while_its_parent_is_trashed(): void
    {
        $parent = Unit::factory()->for($this->company)->create();
        $child = Unit::factory()->childOf($parent)->create();
        $child->delete();
        $parent->delete();

        $this->postJson("/api/v1/units/{$child->id}/restore")->assertConflict();

        $this->postJson("/api/v1/units/{$parent->id}/restore")->assertOk();
        $this->postJson("/api/v1/units/{$child->id}/restore")->assertOk();
    }

    public function test_restoring_a_live_unit_is_a_404(): void
    {
        $unit = Unit::factory()->for($this->company)->create();

        $this->postJson("/api/v1/units/{$unit->id}/restore")->assertNotFound();
    }

    /**
     * RM-34 : chaque changement d'organisation est tracé.
     */
    public function test_rm34_unit_changes_are_written_to_the_audit_log(): void
    {
        $id = $this->postJson('/api/v1/units', ['name' => 'Produit'])->json('data.id');
        $this->patchJson("/api/v1/units/{$id}", ['name' => 'Produit et design']);
        $this->deleteJson("/api/v1/units/{$id}");
        $this->postJson("/api/v1/units/{$id}/restore");

        $events = AuditEntry::query()
            ->where('subject_type', (new Unit)->getMorphClass())
            ->where('subject_id', $id)
            ->orderBy('id')
            ->pluck('event')
            ->all();

        $this->assertSame(['unit.created', 'unit.updated', 'unit.deleted', 'unit.restored'], $events);
    }
}
