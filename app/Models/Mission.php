<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Mission confiée par le CEO au poste qui reçoit les missions (D-017, D-034).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $position_id
 * @property int|null $agent_id
 * @property int|null $project_id
 * @property int|null $activity_id
 * @property string $brief
 * @property string $status
 * @property string|null $outcome
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['company_id', 'position_id', 'agent_id', 'project_id', 'activity_id', 'brief', 'status', 'outcome', 'closed_at'])]
class Mission extends Model
{
    use Audited;
    use SoftDeletes;

    /**
     * received : reçue, pas encore organisée ; in_progress : rattachée à une
     * activité ; done : rendue ; cancelled : annulée par le CEO.
     */
    public const array STATUSES = ['received', 'in_progress', 'done', 'cancelled'];

    public function isOpen(): bool
    {
        return in_array($this->status, ['received', 'in_progress'], true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }
}
