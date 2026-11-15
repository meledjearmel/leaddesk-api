<?php

namespace App\Models;

use App\Enums\OfficePlanStatus;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Plan d'aménagement du bureau, au format `OfficePlan.toJson()` de
 * l'application (D-035).
 *
 * @property int $id
 * @property int $company_id
 * @property int $version
 * @property OfficePlanStatus $status
 * @property array{version?: int, cols: int, rows: int, rooms: list<array{id: string, type: string, department?: string|null, name?: string|null, x: int, y: int, cols: int, rows: int}>} $layout
 * @property int|null $proposed_by_position_id
 * @property int|null $proposed_by_agent_id
 * @property int|null $based_on_id
 * @property string|null $reason
 * @property string|null $decision_comment
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['company_id', 'version', 'status', 'layout', 'proposed_by_position_id', 'proposed_by_agent_id', 'based_on_id', 'reason', 'decision_comment', 'decided_at'])]
class OfficePlan extends Model
{
    use Audited;
    use SoftDeletes;

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function proposedByPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'proposed_by_position_id');
    }

    /**
     * @return BelongsTo<OfficePlan, $this>
     */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_id');
    }

    /**
     * Journal : le plan complet n'y est pas recopié, seulement ses changements
     * d'état. La ressource d'API le renvoie explicitement.
     *
     * @var list<string>
     */
    protected $hidden = ['layout'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OfficePlanStatus::class,
            'layout' => 'array',
            'version' => 'integer',
            'decided_at' => 'datetime',
        ];
    }
}
