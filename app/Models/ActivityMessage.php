<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Message de la conversation de travail d'une activité.
 *
 * @property int $id
 * @property int $company_id
 * @property int $activity_id
 * @property int|null $task_id
 * @property int|null $agent_id
 * @property string $kind
 * @property string $body
 * @property list<array{label: string, path: string, device_id: int}>|null $file_references
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Agent|null $agent
 */
#[Fillable(['company_id', 'activity_id', 'task_id', 'agent_id', 'kind', 'body', 'file_references'])]
class ActivityMessage extends Model
{
    use Audited;
    use SoftDeletes;

    /**
     * message : échange ordinaire ; handoff : passation d'un travail ou de fichiers.
     */
    public const array KINDS = ['message', 'handoff'];

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class)->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_references' => 'array',
        ];
    }
}
