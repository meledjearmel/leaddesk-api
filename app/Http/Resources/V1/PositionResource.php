<?php

namespace App\Http\Resources\V1;

use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Position
 */
class PositionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            /** Poste supérieur ; vide quand le poste rend compte au CEO. */
            'reports_to_id' => $this->reports_to_id,
            'title' => $this->title,
            'mission' => $this->mission,
            'responsibilities' => $this->responsibilities ?? [],
            /** Vrai pour le poste de responsable de l'unité. */
            'is_head' => $this->is_head,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            /** Renseigné quand le poste est à la corbeille. */
            'deleted_at' => $this->deleted_at,
        ];
    }
}
