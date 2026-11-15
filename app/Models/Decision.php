<?php

namespace App\Models;

use App\Enums\DecisionStatus;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Demande soumise à la décision du CEO humain.
 *
 * @property int $id
 * @property int $company_id
 * @property string $kind
 * @property string $title
 * @property DecisionStatus $status
 * @property int|null $requested_by_agent_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $payload
 * @property string|null $decision_note
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_id', 'kind', 'title', 'status', 'requested_by_agent_id', 'subject_type', 'subject_id', 'payload', 'decision_note', 'decided_at'])]
class Decision extends Model
{
    use Audited;

    public const string KIND_RULE_CHANGE = 'rule_change';

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'requested_by_agent_id')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DecisionStatus::class,
            'payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }
}
