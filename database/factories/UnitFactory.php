<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->words(2, true),
            'kind' => 'Département',
        ];
    }

    public function childOf(Unit $parent): static
    {
        return $this->state(fn (): array => [
            'company_id' => $parent->company_id,
            'parent_id' => $parent->id,
        ]);
    }
}
