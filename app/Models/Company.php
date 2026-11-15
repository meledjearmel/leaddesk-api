<?php

namespace App\Models;

use App\Enums\CompanyTemplate;
use App\Models\Concerns\Audited;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Entreprise du CEO. Un compte correspond à une entreprise (RM-01).
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property CompanyTemplate $template
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['user_id', 'name', 'template', 'settings'])]
class Company extends Model
{
    use Audited;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use SoftDeletes;

    public function auditCompanyId(): int
    {
        return $this->id;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * @return HasMany<Position, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * @return HasMany<Agent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /**
     * @return HasMany<AiProvider, $this>
     */
    public function aiProviders(): HasMany
    {
        return $this->hasMany(AiProvider::class);
    }

    /**
     * @return HasMany<AuditEntry, $this>
     */
    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => CompanyTemplate::class,
            'settings' => 'array',
        ];
    }
}
