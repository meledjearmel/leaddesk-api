<?php

namespace App\Models;

use App\Enums\StepCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Étape d'un workflow.
 *
 * @property int $id
 * @property int $workflow_id
 * @property string $name
 * @property StepCategory $category
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['workflow_id', 'name', 'category', 'sort_order'])]
class WorkflowStep extends Model
{
    use SoftDeletes;

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => StepCategory::class,
            'sort_order' => 'integer',
        ];
    }
}
