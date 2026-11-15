<?php

namespace App\Actions\Work;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Task;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Replace les activités et les tâches en cours sur une étape valide quand le
 * parcours de leur type change : autre workflow choisi, étape retirée.
 *
 * RM-21 : une modification de type d'activité s'applique aussi aux activités
 * en cours. Un élément garde son avancement : il va sur la première étape de
 * même catégorie du nouveau parcours, sinon sur l'étape de départ.
 */
class RealignWorkflowSteps
{
    public function forType(ActivityType $type): void
    {
        $type->unsetRelation('activityWorkflow')->unsetRelation('taskWorkflow');

        $this->realign(
            Activity::query()->where('activity_type_id', $type->id),
            $type->activityWorkflow,
        );

        $this->realign(
            Task::query()->whereIn(
                'activity_id',
                Activity::query()->where('activity_type_id', $type->id)->select('id'),
            ),
            $type->taskWorkflow,
        );
    }

    /**
     * À appeler quand les étapes d'un workflow ont changé.
     */
    public function forWorkflow(Workflow $workflow): void
    {
        ActivityType::query()
            ->where('activity_workflow_id', $workflow->id)
            ->orWhere('task_workflow_id', $workflow->id)
            ->get()
            ->each(fn (ActivityType $type) => $this->forType($type));
    }

    /**
     * @template TModel of Activity|Task
     *
     * @param  Builder<TModel>  $items
     */
    private function realign(Builder $items, ?Workflow $workflow): void
    {
        $steps = $workflow?->steps()->get() ?? collect();
        $validIds = $steps->pluck('id')->all();

        $misplaced = $items
            ->where(fn (Builder $query) => $query
                ->whereNull('workflow_step_id')
                ->orWhereNotIn('workflow_step_id', $validIds))
            ->get();

        foreach ($misplaced as $item) {
            /** @var Model&(Activity|Task) $item */
            $previous = $item->workflow_step_id === null
                ? null
                : WorkflowStep::withTrashed()->find($item->workflow_step_id);

            $target = $previous === null
                ? null
                : $steps->firstWhere('category', $previous->category);

            $targetId = ($target ?? $steps->first())?->id;

            if ($targetId !== $item->workflow_step_id) {
                $item->update(['workflow_step_id' => $targetId]);
            }
        }
    }
}
