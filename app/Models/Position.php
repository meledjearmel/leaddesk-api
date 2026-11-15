<?php

namespace App\Models;

use App\Enums\LifecycleStatus;
use App\Models\Concerns\Audited;
use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Poste : une fonction et ses responsabilités dans l'organisation.
 *
 * @property int $id
 * @property int $company_id
 * @property int $unit_id
 * @property int|null $reports_to_id
 * @property string $title
 * @property string|null $mission
 * @property list<string>|null $responsibilities
 * @property bool $is_head
 * @property LifecycleStatus $status
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Position|null $reportsTo
 */
#[Fillable(['company_id', 'unit_id', 'reports_to_id', 'title', 'mission', 'responsibilities', 'is_head', 'status', 'sort_order'])]
class Position extends Model
{
    use Audited;

    /** @use HasFactory<PositionFactory> */
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
     * Poste supérieur hiérarchique. Vide : le poste rend compte au CEO.
     *
     * @return BelongsTo<Position, $this>
     */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    /**
     * @return HasMany<Position, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to_id');
    }

    /**
     * Vrai si ce poste est $other ou lui rend compte, directement ou non.
     */
    public function isSelfOrReportsTo(Position $other): bool
    {
        $current = $this;
        $seen = [];

        while ($current !== null && ! isset($seen[$current->id])) {
            if ($current->id === $other->id) {
                return true;
            }

            $seen[$current->id] = true;
            $current = $current->reportsTo;
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'responsibilities' => 'array',
            'is_head' => 'boolean',
            'status' => LifecycleStatus::class,
            'sort_order' => 'integer',
        ];
    }
}
