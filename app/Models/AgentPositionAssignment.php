<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Affectation d'un agent à un poste, datée. L'ensemble forme son parcours.
 *
 * @property int $id
 * @property int $agent_id
 * @property int $position_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
#[Fillable(['agent_id', 'position_id', 'started_at', 'ended_at'])]
class AgentPositionAssignment extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Le poste reste lisible dans le parcours même s'il est à la corbeille.
     *
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class)->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
