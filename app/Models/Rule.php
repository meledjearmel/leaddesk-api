<?php

namespace App\Models;

use App\Enums\RuleRelation;
use App\Models\Concerns\Audited;
use Database\Factories\RuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Règle de l'entreprise ou d'une unité, héritée par les niveaux inférieurs.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $unit_id
 * @property int|null $parent_rule_id
 * @property RuleRelation|null $relation
 * @property string $title
 * @property string $body
 * @property int $version
 * @property Carbon $effective_at
 * @property bool $extended_to_running
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Unit|null $unit
 */
#[Fillable(['company_id', 'unit_id', 'parent_rule_id', 'relation', 'title', 'body', 'version', 'effective_at', 'extended_to_running'])]
class Rule extends Model
{
    use Audited;

    /** @use HasFactory<RuleFactory> */
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
     * @return BelongsTo<Rule, $this>
     */
    public function parentRule(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_rule_id');
    }

    /**
     * Règles des niveaux inférieurs qui précisent ou renforcent celle-ci.
     *
     * @return HasMany<Rule, $this>
     */
    public function refinements(): HasMany
    {
        return $this->hasMany(self::class, 'parent_rule_id');
    }

    /**
     * Vrai si cette règle s'applique à l'unité donnée : règle d'entreprise,
     * règle de l'unité elle-même ou d'une unité au-dessus d'elle.
     */
    public function appliesToUnit(Unit $unit): bool
    {
        return $this->unit === null || $unit->isSelfOrDescendantOf($this->unit);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => RuleRelation::class,
            'version' => 'integer',
            'effective_at' => 'datetime',
            'extended_to_running' => 'boolean',
        ];
    }
}
