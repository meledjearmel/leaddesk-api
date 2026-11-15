<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\AiProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Fournisseur de modèles IA connecté par l'entreprise (RM-10).
 * La clé d'API n'est jamais stockée ici : elle reste sur l'ordinateur du CEO.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $driver
 * @property string|null $base_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['company_id', 'name', 'driver', 'base_url'])]
class AiProvider extends Model
{
    use Audited;

    /** @use HasFactory<AiProviderFactory> */
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
     * @return HasMany<AiModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }
}
