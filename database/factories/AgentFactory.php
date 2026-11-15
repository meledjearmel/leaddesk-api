<?php

namespace Database\Factories;

use App\Enums\LifecycleStatus;
use App\Models\Agent;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->firstName(),
            'status' => LifecycleStatus::Draft,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => LifecycleStatus::Active]);
    }
}
