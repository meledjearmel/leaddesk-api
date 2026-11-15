<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\Task;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'company_id' => fn (array $attributes): int => Activity::query()
                ->whereKey($attributes['activity_id'])
                ->firstOrFail()
                ->company_id,
            'unit_id' => fn (array $attributes): int => Unit::factory()
                ->create(['company_id' => $attributes['company_id']])
                ->id,
            'title' => 'Tâche '.fake()->unique()->numberBetween(1, 1_000_000),
        ];
    }

    public function in(Activity $activity, Unit $unit): static
    {
        return $this->state(fn (): array => [
            'company_id' => $activity->company_id,
            'activity_id' => $activity->id,
            'unit_id' => $unit->id,
            'workflow_step_id' => $activity->type->taskWorkflow?->initialStep()?->id,
        ]);
    }
}
