<?php

namespace Tests\Feature\Api\V1;

use App\Broadcasting\CompanyChannel;
use App\Events\CompanyChanged;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2 : le CEO voit les changements sans recharger.
 */
class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * D-040 : l'application apprend où se connecter, sans jamais recevoir le secret.
     */
    public function test_the_app_gets_the_public_connection_details_and_its_channel(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'cle-publique',
            'broadcasting.connections.reverb.secret' => 'secret-serveur',
            'broadcasting.connections.reverb.options' => ['host' => 'temps-reel.test', 'port' => 443, 'scheme' => 'https'],
        ]);
        $company = Company::factory()->create();
        Sanctum::actingAs($company->user);

        $response = $this->getJson('/api/v1/realtime')
            ->assertOk()
            ->assertExactJson(['data' => [
                'key' => 'cle-publique',
                'host' => 'temps-reel.test',
                'port' => 443,
                'scheme' => 'wss',
                'channel' => 'private-company.'.$company->id,
                'event' => 'company.changed',
            ]]);

        $this->assertStringNotContainsString('secret-serveur', (string) $response->getContent());
        $this->app['auth']->forgetGuards();
    }

    public function test_the_realtime_details_require_a_token(): void
    {
        $this->getJson('/api/v1/realtime')->assertUnauthorized();
    }

    /**
     * Les propositions d'aménagement et les missions sont diffusées comme le reste.
     */
    public function test_office_plans_are_broadcast_too(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/company', ['name' => 'Atelier', 'template' => 'blank'])->assertCreated();
        $company = Company::query()->sole();
        $hr = (string) $company->units()->where('system_role', 'hr')->sole()->id;
        $facilities = (string) $company->units()->where('system_role', 'facilities')->sole()->id;
        Event::fake([CompanyChanged::class]);

        $this->putJson('/api/v1/office/plan', ['layout' => ['cols' => 20, 'rows' => 10, 'rooms' => [
            ['id' => 'rh', 'type' => 'private_office', 'department' => $hr, 'x' => 0, 'y' => 0, 'cols' => 3, 'rows' => 3],
            ['id' => 'mg', 'type' => 'private_office', 'department' => $facilities, 'x' => 4, 'y' => 0, 'cols' => 3, 'rows' => 3],
        ]]])->assertCreated();

        Event::assertDispatched(fn (CompanyChanged $event): bool => $event->broadcastWith()['event'] === 'office_plan.created');
    }

    public function test_every_change_is_broadcast_on_the_private_channel_of_the_company(): void
    {
        $company = Company::factory()->create();
        Sanctum::actingAs($company->user);
        Event::fake([CompanyChanged::class]);

        $id = $this->postJson('/api/v1/units', ['name' => 'Produit'])->assertCreated()->json('data.id');

        Event::assertDispatchedTimes(CompanyChanged::class, 1);
        Event::assertDispatched(function (CompanyChanged $event) use ($company, $id): bool {
            $channel = $event->broadcastOn();
            $payload = $event->broadcastWith();

            return $channel instanceof PrivateChannel
                && $channel->name === 'private-company.'.$company->id
                && $event->broadcastAs() === 'company.changed'
                && $payload['event'] === 'unit.created'
                && $payload['subject_id'] === $id
                && $payload['actor_type'] === 'ceo'
                && $payload['kind'] === 'change'
                && $payload['id'] === AuditEntry::query()->latest('id')->value('id');
        });
    }

    public function test_the_event_carries_no_business_data(): void
    {
        $company = Company::factory()->create(['name' => 'Nom confidentiel']);
        $event = new CompanyChanged(AuditEntry::query()->where('company_id', $company->id)->sole());

        $this->assertSame(
            ['id', 'event', 'kind', 'actor_type', 'subject_id', 'occurred_at'],
            array_keys($event->broadcastWith()),
        );
        $this->assertStringNotContainsString('Nom confidentiel', (string) json_encode($event->broadcastWith()));
    }

    public function test_the_event_is_queued_and_waits_for_the_transaction_to_commit(): void
    {
        $this->assertContains(ShouldBroadcast::class, class_implements(CompanyChanged::class) ?: []);
        $this->assertContains(ShouldDispatchAfterCommit::class, class_implements(CompanyChanged::class) ?: []);
    }

    public function test_only_the_ceo_of_a_company_may_join_its_channel(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $channel = new CompanyChannel;

        $this->assertTrue($channel->join($company->user, $company->id));
        $this->assertTrue($channel->join($company->user, (string) $company->id));
        $this->assertFalse($channel->join($company->user, $other->id));
        $this->assertFalse($channel->join(User::factory()->create(), $company->id));
    }

    public function test_channel_authorization_requires_a_token(): void
    {
        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-company.1',
        ])->assertUnauthorized();
    }
}
