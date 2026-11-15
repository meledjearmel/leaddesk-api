<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Élément du contexte partagé d'une activité : une information que les
 * participants gardent sous les yeux (objectif, contrainte, choix retenu).
 *
 * @property int $id
 * @property int $company_id
 * @property int $activity_id
 * @property int|null $agent_id
 * @property string $title
 * @property string $content
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['company_id', 'activity_id', 'agent_id', 'title', 'content', 'position'])]
class ActivityContextItem extends Model
{
    use Audited;
    use SoftDeletes;
}
