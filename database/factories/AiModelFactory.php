<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_provider_id' => AiProvider::factory(),
            'company_id' => fn (array $attributes): int => AiProvider::query()
                ->whereKey($attributes['ai_provider_id'])
                ->firstOrFail()
                ->company_id,
            'identifier' => 'modele-'.fake()->unique()->numberBetween(1, 1_000_000),
            'label' => null,
        ];
    }

    public function of(AiProvider $provider): static
    {
        return $this->state(fn (): array => [
            'company_id' => $provider->company_id,
            'ai_provider_id' => $provider->id,
        ]);
    }
}
