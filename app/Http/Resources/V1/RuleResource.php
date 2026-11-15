<?php

namespace App\Http\Resources\V1;

use App\Models\Rule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rule
 */
class RuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Niveau de la règle ; vide pour une règle de toute l'entreprise. */
            'unit_id' => $this->unit_id,
            /** Règle héritée que celle-ci précise ou renforce. */
            'parent_rule_id' => $this->parent_rule_id,
            'relation' => $this->relation,
            'title' => $this->title,
            'body' => $this->body,
            'version' => $this->version,
            /** La règle vaut pour les activités créées à partir de cette date. */
            'effective_at' => $this->effective_at,
            /** Vrai quand le CEO a étendu la règle aux activités déjà en cours. */
            'extended_to_running' => $this->extended_to_running,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
