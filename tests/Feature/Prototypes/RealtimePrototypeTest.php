<?php

namespace Tests\Feature\Prototypes;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Prototypes\Realtime\PrototypePing;
use Tests\TestCase;

/**
 * Prototype 3 (phase 0) : jeton Sanctum et événement Reverb.
 */
class RealtimePrototypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_return_a_working_token(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $response = $this->postJson('/api/prototypes/realtime/token', [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'pc-test',
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('channel', 'prototypes.user.'.$user->id);

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->postJson('/api/prototypes/realtime/token', [
            'email' => $user->email,
            'password' => 'wrong',
            'device_name' => 'pc-test',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_ping_requires_a_token(): void
    {
        $this->postJson('/api/prototypes/realtime/ping', ['message' => 'bonjour'])
            ->assertUnauthorized();
    }

    public function test_ping_broadcasts_on_the_private_channel_of_the_caller(): void
    {
        Event::fake([PrototypePing::class]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/prototypes/realtime/ping', ['message' => 'bonjour'])
            ->assertOk()
            ->assertJsonStructure(['sent_at']);

        Event::assertDispatched(function (PrototypePing $event) use ($user): bool {
            $channel = $event->broadcastOn();

            return $event->userId === $user->id
                && $event->message === 'bonjour'
                && $channel instanceof PrivateChannel
                && $channel->name === 'private-prototypes.user.'.$user->id
                && $event->broadcastAs() === 'prototype.ping'
                && $event->broadcastWith()['message'] === 'bonjour';
        });
    }

    public function test_channel_authorization_route_requires_a_token(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-prototypes.user.1',
        ])->assertUnauthorized();
    }
}
