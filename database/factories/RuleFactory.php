<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Rule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rule>
 */
class RuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'title' => 'Règle '.fake()->unique()->numberBetween(1, 1_000_000),
            'body' => 'Texte de la règle.',
            'effective_at' => now(),
        ];
    }
}
