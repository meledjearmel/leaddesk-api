<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tâche : une part du travail d'une activité, confiée à une équipe.
 *
 * @property int $id
 * @property int $company_id
 * @property int $activity_id
 * @property int $unit_id
 * @property string $title
 * @property string|null $description
 * @property int|null $workflow_step_id
 * @property int|null $confirmed_by_agent_id
 * @property bool $confirmed_by_ceo
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Activity $activity
 * @property-read Unit $unit
 * @property-read WorkflowStep|null $step
 */
#[Fillable(['company_id', 'activity_id', 'unit_id', 'title', 'description', 'workflow_step_id', 'confirmed_by_agent_id', 'confirmed_by_ceo', 'completed_at'])]
class Task extends Model
{
    use Audited;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /**
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    /**
     * Agents affectés, l'agent principal d'abord.
     *
     * @return BelongsToMany<Agent, $this>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_task')
            ->withPivot('is_lead')
            ->withTimestamps()
            ->orderByDesc('agent_task.is_lead')
            ->orderBy('agent_task.id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_by_ceo' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }
}
