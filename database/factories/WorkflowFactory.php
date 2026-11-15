<?php

namespace Database\Factories;

use App\Enums\StepCategory;
use App\Enums\WorkflowScope;
use App\Models\Company;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => 'Parcours '.fake()->unique()->numberBetween(1, 1_000_000),
            'scope' => WorkflowScope::Activity,
        ];
    }

    public function forTasks(): static
    {
        return $this->state(fn (): array => ['scope' => WorkflowScope::Task]);
    }

    /**
     * Ajoute les étapes usuelles : à faire, en cours, à confirmer, terminé.
     */
    public function withSteps(): static
    {
        return $this->afterCreating(function (Workflow $workflow): void {
            $steps = [
                ['À faire', StepCategory::Todo],
                ['En cours', StepCategory::InProgress],
                ['À confirmer', StepCategory::Review],
                ['Terminé', StepCategory::Done],
            ];

            foreach ($steps as $order => [$name, $category]) {
                $workflow->steps()->create(['name' => $name, 'category' => $category, 'sort_order' => $order]);
            }
        });
    }
}
