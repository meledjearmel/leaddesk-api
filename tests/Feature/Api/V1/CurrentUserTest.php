<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CurrentUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_the_current_user(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_token_holder_reads_the_current_user_without_secrets(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.company', null)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.two_factor_secret');
    }

    public function test_the_current_user_carries_its_company(): void
    {
        $company = Company::factory()->create(['name' => 'Atelier Nord']);

        Sanctum::actingAs($company->user);

        $this->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.company.name', 'Atelier Nord');
    }

    public function test_a_real_personal_access_token_authenticates(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('desktop')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }
}
