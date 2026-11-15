<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_the_account_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'device_name' => 'PC bureau',
        ])->assertCreated()
            ->assertJsonPath('user.email', 'ada@example.com')
            ->assertJsonPath('user.company', null);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.name', 'Ada Lovelace');
    }

    public function test_registration_validates_its_input(): void
    {
        User::factory()->create(['email' => 'pris@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'pris@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['device_name']);

        $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'pris@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'different',
            'device_name' => 'PC',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_valid_credentials_return_a_token(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'PC bureau',
        ])->assertOk()->assertJsonPath('user.id', $user->id);

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('PC bureau', $user->tokens()->sole()->name);
    }

    public function test_wrong_credentials_are_rejected_without_revealing_which_part_is_wrong(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $wrongPassword = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'wrong',
            'device_name' => 'PC',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $unknownEmail = $this->postJson('/api/v1/auth/token', [
            'email' => 'inconnu@example.com',
            'password' => 'secret-password',
            'device_name' => 'PC',
        ])->assertUnprocessable();

        $this->assertSame($wrongPassword->json('errors'), $unknownEmail->json('errors'));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_logout_revokes_only_the_token_of_the_calling_device(): void
    {
        $user = User::factory()->create();
        $desktop = $user->createToken('PC bureau')->plainTextToken;
        $laptop = $user->createToken('PC portable')->plainTextToken;

        $this->withToken($desktop)->deleteJson('/api/v1/auth/token')->assertNoContent();

        $this->assertSame(['PC portable'], $user->tokens()->pluck('name')->all());

        $this->app['auth']->forgetGuards();
        $this->withToken($desktop)->getJson('/api/v1/user')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($laptop)->getJson('/api/v1/user')->assertOk();
    }
}
