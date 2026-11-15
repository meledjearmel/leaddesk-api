<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Ordinateur du CEO sur lequel l'application est installée.
 *
 * @property int $id
 * @property int $company_id
 * @property string $installation_id
 * @property string $name
 * @property string $platform
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['company_id', 'installation_id', 'name', 'platform', 'last_seen_at'])]
class Device extends Model
{
    use Audited;

    /** @use HasFactory<DeviceFactory> */
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
     * Vrai si l'ordinateur a donné signe de vie récemment. Le délai est un
     * réglage (config/leaddesk.php), pas une constante.
     */
    public function isOnline(): bool
    {
        /** @var int $seconds */
        $seconds = config('leaddesk.device_online_seconds');

        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(now()->subSeconds($seconds));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
