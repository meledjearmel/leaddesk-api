<?php

namespace App\Http\Resources\V1;

use App\Models\AgentPositionAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentPositionAssignment
 */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position_id' => $this->position_id,
            /** Intitulé du poste, lisible même si le poste est à la corbeille. */
            'position_title' => $this->position?->title,
            'started_at' => $this->started_at,
            /** Vide pour l'affectation en cours. */
            'ended_at' => $this->ended_at,
        ];
    }
}
