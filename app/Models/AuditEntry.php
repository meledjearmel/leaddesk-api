<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\AuditKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Entrée du journal de traçabilité (RM-34). Jamais modifiée ni supprimée.
 *
 * @property int $id
 * @property int $company_id
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property AuditKind $kind
 * @property string $event
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $properties
 * @property Carbon $created_at
 */
#[Fillable(['company_id', 'actor_type', 'actor_id', 'kind', 'event', 'subject_type', 'subject_id', 'properties'])]
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'kind' => AuditKind::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
