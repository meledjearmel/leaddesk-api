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
