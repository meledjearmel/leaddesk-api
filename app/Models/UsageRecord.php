<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Coût réel d'un appel de modèle ou d'outil (RM-35). Jamais modifié.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $task_run_id
 * @property int|null $agent_id
 * @property int|null $task_id
 * @property int|null $activity_id
 * @property int|null $project_id
 * @property string $kind
 * @property string|null $provider
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int|null $cost_micros
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
#[Fillable(['company_id', 'task_run_id', 'agent_id', 'task_id', 'activity_id', 'project_id', 'kind', 'provider', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost_micros', 'occurred_at'])]
class UsageRecord extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cost_micros' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
