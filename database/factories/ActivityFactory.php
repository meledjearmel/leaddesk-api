<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_type_id' => ActivityType::factory(),
            'company_id' => fn (array $attributes): int => ActivityType::query()
                ->whereKey($attributes['activity_type_id'])
                ->firstOrFail()
                ->company_id,
            'title' => 'Activité '.fake()->unique()->numberBetween(1, 1_000_000),
        ];
    }

    public function ofType(ActivityType $type): static
    {
        return $this->state(fn (): array => [
            'company_id' => $type->company_id,
            'activity_type_id' => $type->id,
            'workflow_step_id' => $type->activityWorkflow?->initialStep()?->id,
        ]);
    }
}
