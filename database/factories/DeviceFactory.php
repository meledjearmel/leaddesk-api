<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'installation_id' => fake()->uuid(),
            'name' => 'PC '.fake()->unique()->numberBetween(1, 1_000_000),
            'platform' => 'windows',
            'last_seen_at' => now(),
        ];
    }
}
