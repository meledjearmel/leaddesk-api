<?php

namespace Database\Factories;

use App\Enums\LifecycleStatus;
use App\Models\Position;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'company_id' => fn (array $attributes): int => Unit::query()->whereKey($attributes['unit_id'])->firstOrFail()->company_id,
            'title' => fake()->jobTitle(),
            'status' => LifecycleStatus::Active,
        ];
    }

    public function in(Unit $unit): static
    {
        return $this->state(fn (): array => [
            'company_id' => $unit->company_id,
            'unit_id' => $unit->id,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => LifecycleStatus::Draft]);
    }
}
