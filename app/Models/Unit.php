<?php

namespace App\Models;

use App\Enums\LifecycleStatus;
use App\Enums\SystemRole;
use App\Models\Concerns\Audited;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Unité de l'organisation (département, pôle, équipe…), imbriquable.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $kind
 * @property SystemRole|null $system_role
 * @property string|null $color
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Unit|null $parent
 */
#[Fillable(['company_id', 'parent_id', 'name', 'kind', 'system_role', 'color', 'sort_order'])]
class Unit extends Model
{
    use Audited;

    /** @use HasFactory<UnitFactory> */
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
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Position, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * Responsable de l'équipe : l'agent en service qui occupe le poste de
     * responsable de cette unité, ou à défaut celui de l'unité parente la plus
     * proche. Vide si aucune unité de la chaîne n'a de responsable en service.
     */
    public function responsibleAgent(): ?Agent
    {
        $current = $this;
        $seen = [];

        while ($current !== null && ! isset($seen[$current->id])) {
            $agent = Agent::query()
                ->where('status', LifecycleStatus::Active)
                ->whereIn('position_id', $current->positions()->where('is_head', true)->select('id'))
                ->orderBy('id')
                ->first();

            if ($agent !== null) {
                return $agent;
            }

            $seen[$current->id] = true;
            $current = $current->parent;
        }

        return null;
    }

    /**
     * Vrai si cette unité est $other ou se trouve sous $other dans l'arbre.
     */
    public function isSelfOrDescendantOf(Unit $other): bool
    {
        $current = $this;
        $seen = [];

        while ($current !== null && ! isset($seen[$current->id])) {
            if ($current->id === $other->id) {
                return true;
            }

            $seen[$current->id] = true;
            $current = $current->parent;
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'system_role' => SystemRole::class,
        ];
    }
}
