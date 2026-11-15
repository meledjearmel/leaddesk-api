<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\ActivityTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Type d'activité : un modèle de travail configurable.
 *
 * @phpstan-type FieldDefinition array{key: string, label: string, type: string, required: bool, options: list<string>}
 * @phpstan-type TaskTemplate array{title: string, description: string|null}
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $description
 * @property int|null $unit_id
 * @property int|null $activity_workflow_id
 * @property int|null $task_workflow_id
 * @property list<FieldDefinition>|null $fields
 * @property list<TaskTemplate>|null $task_templates
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Workflow|null $activityWorkflow
 * @property-read Workflow|null $taskWorkflow
 */
#[Fillable(['company_id', 'name', 'description', 'unit_id', 'activity_workflow_id', 'task_workflow_id', 'fields', 'task_templates'])]
class ActivityType extends Model
{
    use Audited;

    /** @use HasFactory<ActivityTypeFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function activityWorkflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'activity_workflow_id');
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function taskWorkflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'task_workflow_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'task_templates' => 'array',
        ];
    }
}
