<?php

namespace Database\Factories;

use App\Models\ActivityType;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityType>
 */
class ActivityTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => 'Type '.fake()->unique()->numberBetween(1, 1_000_000),
            'fields' => [],
            'task_templates' => [],
        ];
    }
}
