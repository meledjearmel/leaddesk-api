<?php

namespace App\Models;

use App\Enums\LifecycleStatus;
use App\Models\Concerns\Audited;
use Database\Factories\AiModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Modèle IA disponible chez un fournisseur connecté.
 *
 * @property int $id
 * @property int $company_id
 * @property int $ai_provider_id
 * @property string $identifier
 * @property string|null $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['company_id', 'ai_provider_id', 'identifier', 'label'])]
class AiModel extends Model
{
    use Audited;

    /** @use HasFactory<AiModelFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * @return BelongsToMany<Agent, $this>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_ai_model');
    }

    /**
     * Vrai si un agent en service n'a que ce modèle (ou ces modèles) pour
     * travailler : le retirer le laisserait actif sans modèle (RM-06).
     *
     * @param  list<int>  $modelIds
     */
    public static function wouldStrandActiveAgents(array $modelIds): bool
    {
        return Agent::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas('aiModels', fn ($query) => $query->whereIn('ai_models.id', $modelIds))
            ->whereDoesntHave(
                'aiModels',
                fn ($query) => $query->whereNotIn('ai_models.id', $modelIds)->whereHas('provider'),
            )
            ->exists();
    }
}
