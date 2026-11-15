<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Dossier autorisé d'un projet sur un ordinateur.
 *
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property int $device_id
 * @property string $kind
 * @property string $path
 * @property Carbon $authorized_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_id', 'project_id', 'device_id', 'kind', 'path', 'authorized_at'])]
class ProjectFolder extends Model
{
    use Audited;

    public const array KINDS = ['documentation', 'code'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authorized_at' => 'datetime',
        ];
    }
}
