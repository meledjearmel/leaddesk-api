<?php

namespace App\Http\Resources\V1;

use App\Models\AiProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiProvider
 */
class AiProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /** Type de source, par exemple « anthropic » ou « openrouter ». */
            'driver' => $this->driver,
            /** Adresse de l'API si elle diffère de celle par défaut du type. */
            'base_url' => $this->base_url,
            'models' => AiModelResource::collection($this->whenLoaded('models')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
