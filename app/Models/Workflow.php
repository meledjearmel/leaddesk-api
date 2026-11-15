<?php

namespace App\Models;

use App\Enums\WorkflowScope;
use App\Models\Concerns\Audited;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Parcours configurable d'activités ou de tâches (RM-18).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property WorkflowScope $scope
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['company_id', 'name', 'scope'])]
class Workflow extends Model
{
    use Audited;

    /** @use HasFactory<WorkflowFactory> */
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
     * Étapes dans l'ordre du parcours.
     *
     * @return HasMany<WorkflowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Étape de départ : la première du parcours.
     */
    public function initialStep(): ?WorkflowStep
    {
        return $this->steps()->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => WorkflowScope::class,
        ];
    }
}
