<?php

namespace App\Models;

use App\Enums\CostScope;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Limite de coût de l'entreprise, d'un projet ou d'une tâche (RM-36).
 * Une valeur réglée par le CEO, jamais une constante du logiciel.
 *
 * @property int $id
 * @property int $company_id
 * @property CostScope $scope
 * @property int $scope_id
 * @property int $limit_micros
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_id', 'scope', 'scope_id', 'limit_micros'])]
class CostLimit extends Model
{
    use Audited;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => CostScope::class,
            'scope_id' => 'integer',
            'limit_micros' => 'integer',
        ];
    }
}
