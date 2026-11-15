<?php

namespace App\Models;

use App\Enums\LifecycleStatus;
use App\Models\Concerns\Audited;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Agent : une personne IA à l'identité stable, affectée à un poste (RM-05).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $position_id
 * @property string $name
 * @property string|null $bio
 * @property LifecycleStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['company_id', 'position_id', 'name', 'bio', 'status'])]
class Agent extends Model
{
    use Audited;

    /** @use HasFactory<AgentFactory> */
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
     * Poste actuel. Un agent n'occupe qu'un poste à la fois.
     *
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * Parcours de l'agent : toutes ses affectations, de la plus récente à la plus ancienne.
     *
     * @return HasMany<AgentPositionAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(AgentPositionAssignment::class)->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * Modèles IA affectés à l'agent.
     *
     * @return BelongsToMany<AiModel, $this>
     */
    public function aiModels(): BelongsToMany
    {
        return $this->belongsToMany(AiModel::class, 'agent_ai_model')
            ->withPivot(['purpose', 'is_default'])
            ->withTimestamps();
    }

    /**
     * RM-06 : un agent est activable dès qu'un modèle IA utilisable lui est
     * configuré. Aucun accès MCP n'est exigé.
     */
    public function hasUsableAiModel(): bool
    {
        return $this->aiModels()->whereHas('provider')->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LifecycleStatus::class,
        ];
    }
}
