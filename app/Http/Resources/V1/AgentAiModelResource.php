<?php

namespace App\Http\Resources\V1;

use App\Models\AiModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Modèle IA tel qu'il est affecté à un agent.
 *
 * @mixin AiModel
 */
class AgentAiModelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var object{purpose: string|null, is_default: int|bool} $pivot */
        $pivot = $this->resource->getRelation('pivot');

        return [
            'ai_model_id' => $this->id,
            'ai_provider_id' => $this->ai_provider_id,
            'identifier' => $this->identifier,
            'label' => $this->label,
            /** Usage prévu de ce modèle pour cet agent. */
            'purpose' => $pivot->purpose,
            /** Vrai pour le modèle utilisé quand aucun usage ne correspond. */
            'is_default' => (bool) $pivot->is_default,
        ];
    }
}
