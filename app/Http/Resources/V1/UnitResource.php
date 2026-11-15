<?php

namespace App\Http\Resources\V1;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Unit
 */
class UnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Unité parente ; vide pour une unité de premier niveau. */
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            /** Libellé libre du niveau, par exemple « Département » ou « Pôle ». */
            'kind' => $this->kind,
            /**
             * Rôle système (`hr`, `facilities`) : unité présente dans toute
             * entreprise, renommable mais impossible à supprimer. Vide sinon.
             */
            'system_role' => $this->system_role?->value,
            /** Couleur d'affichage au format #RRGGBB. */
            'color' => $this->color,
            /**
             * Activités de l'unité (`code`, `recrutement`…) : l'aménagement
             * en déduit les salles spécialisées dont elle a besoin.
             */
            'activity_tags' => $this->activity_tags ?? [],
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            /** Renseigné quand l'unité est à la corbeille. */
            'deleted_at' => $this->deleted_at,
        ];
    }
}
