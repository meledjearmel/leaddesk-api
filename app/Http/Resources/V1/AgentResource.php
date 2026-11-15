<?php

namespace App\Http\Resources\V1;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Agent
 */
class AgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Poste actuel ; vide si l'agent n'est affecté à aucun poste. */
            'position_id' => $this->position_id,
            'name' => $this->name,
            'bio' => $this->bio,
            'status' => $this->status,
            /** Modèles IA affectés à l'agent. */
            'ai_models' => AgentAiModelResource::collection($this->whenLoaded('aiModels')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            /** Renseigné quand l'agent est à la corbeille. */
            'deleted_at' => $this->deleted_at,
        ];
    }
}
