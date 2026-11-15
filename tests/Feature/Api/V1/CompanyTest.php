<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ActorType;
use App\Enums\AuditKind;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/company')->assertUnauthorized();
        $this->postJson('/api/v1/company', [])->assertUnauthorized();
        $this->patchJson('/api/v1/company', [])->assertUnauthorized();
    }

    public function test_an_account_without_company_gets_a_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/company')->assertNotFound();
    }

    /**
     * RM-02 : une entreprise vierge existe sans poste ni type d'activité.
     */
    public function test_rm02_a_blank_company_is_created_empty(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/company', ['name' => 'Atelier Nord', 'template' => 'blank'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Atelier Nord')
            ->assertJsonPath('data.template', 'blank');

        $this->assertDatabaseHas('companies', ['user_id' => $user->id, 'name' => 'Atelier Nord']);

        $this->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.name', 'Atelier Nord');
    }

    /**
     * RM-02 et D-013 : même vierge, l'entreprise a ses unités RH et Moyens
     * généraux, chacune avec un poste de responsable en brouillon, sans agent.
     */
    public function test_rm02_a_blank_company_has_its_two_system_units(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/company', ['name' => 'Atelier Nord', 'template' => 'blank'])->assertCreated();

        $units = $this->getJson('/api/v1/units')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame(['hr', 'facilities'], array_column($units, 'system_role'));
        $this->assertSame(['Ressources humaines', 'Moyens généraux'], array_column($units, 'name'));

        $positions = $this->getJson('/api/v1/positions')->assertJsonCount(2, 'data')->json('data');
        foreach ($positions as $position) {
            $this->assertTrue($position['is_head']);
            $this->assertSame('draft', $position['status']);
        }
        $this->getJson('/api/v1/agents')->assertJsonCount(0, 'data');
    }

    /**
     * D-013 : une unité système se renomme mais ne se supprime pas.
     */
    public function test_a_system_unit_can_be_renamed_but_not_deleted(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/company', ['name' => 'Atelier Nord', 'template' => 'blank'])->assertCreated();

        $company = Company::query()->sole();
        $facilities = $company->units()->where('system_role', 'facilities')->sole();
        $facilities->positions()->delete();

        $this->patchJson("/api/v1/units/{$facilities->id}", ['name' => 'Logistique', 'system_role' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'Logistique')
            ->assertJsonPath('data.system_role', 'facilities');

        $this->deleteJson("/api/v1/units/{$facilities->id}")->assertConflict();
        $this->assertNotSoftDeleted($facilities);
    }

    /**
     * D-013 : la base refuse deux unités de même rôle dans une entreprise.
     */
    public function test_the_database_refuses_two_units_with_the_same_system_role(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/company', ['name' => 'Atelier Nord', 'template' => 'blank'])->assertCreated();

        $this->expectException(QueryException::class);
        Company::query()->sole()->units()->create(['name' => 'RH bis', 'system_role' => 'hr']);
    }

    public function test_creation_validates_name_and_template(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/company', ['name' => '', 'template' => 'inconnu'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'template']);
    }

    /**
     * RM-01 : un compte correspond à une entreprise.
     */
    public function test_rm01_an_account_cannot_create_a_second_company(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs($company->user);

        $this->postJson('/api/v1/company', ['name' => 'Seconde', 'template' => 'blank'])
            ->assertConflict();

        $this->assertSame(1, Company::query()->count());
    }

    /**
     * RM-01 : la base elle-même refuse deux entreprises actives pour un compte.
     */
    public function test_rm01_the_database_refuses_two_live_companies_for_one_account(): void
    {
        $company = Company::factory()->create();

        $this->expectException(QueryException::class);

        Company::factory()->create(['user_id' => $company->user_id]);
    }

    public function test_the_ceo_renames_the_company(): void
    {
        $company = Company::factory()->create(['name' => 'Ancien nom']);
        Sanctum::actingAs($company->user);

        $this->patchJson('/api/v1/company', ['name' => 'Nouveau nom'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nouveau nom');
    }

    /**
     * RM-34 : les changements sont enregistrés, avec leur auteur.
     */
    public function test_rm34_creation_and_renaming_are_written_to_the_audit_log(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/company', ['name' => 'Atelier Nord', 'template' => 'blank'])->assertCreated();
        $this->patchJson('/api/v1/company', ['name' => 'Atelier Sud'])->assertOk();

        $entries = AuditEntry::query()->where('event', 'like', 'company.%')->orderBy('id')->get();

        $this->assertSame(['company.created', 'company.updated'], $entries->pluck('event')->all());
        // Les unités système (D-013) sont tracées elles aussi.
        $this->assertSame(2, AuditEntry::query()->where('event', 'unit.created')->count());

        $updated = $entries[1];
        $this->assertSame(ActorType::Ceo, $updated->actor_type);
        $this->assertSame($user->id, $updated->actor_id);
        $this->assertSame(AuditKind::Change, $updated->kind);
        $this->assertSame(['name' => 'Atelier Nord'], $updated->properties['before'] ?? null);
        $this->assertSame(['name' => 'Atelier Sud'], $updated->properties['after'] ?? null);
    }

    public function test_a_change_made_without_a_logged_in_user_is_attributed_to_the_system(): void
    {
        Company::factory()->create();

        $this->assertSame(ActorType::System, AuditEntry::query()->sole()->actor_type);
    }
}
