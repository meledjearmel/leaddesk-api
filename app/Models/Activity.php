<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Activité : un travail concret créé depuis un type d'activité.
 *
 * @property int $id
 * @property int $company_id
 * @property int $activity_type_id
 * @property int|null $project_id
 * @property string $title
 * @property string|null $description
 * @property int|null $workflow_step_id
 * @property array<string, mixed>|null $field_values
 * @property Carbon|null $due_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read ActivityType $type
 * @property-read WorkflowStep|null $step
 */
#[Fillable(['company_id', 'activity_type_id', 'project_id', 'title', 'description', 'workflow_step_id', 'field_values', 'due_at'])]
class Activity extends Model
{
    use Audited;

    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Le type reste lisible même s'il est à la corbeille.
     *
     * @return BelongsTo<ActivityType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    /**
     * Équipes mobilisées (RM-17).
     *
     * @return BelongsToMany<Unit, $this>
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'activity_unit')->orderBy('activity_unit.id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_values' => 'array',
            'due_at' => 'datetime',
        ];
    }
}
