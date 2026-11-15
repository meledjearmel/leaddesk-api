<?php

namespace App\Http\Resources\V1;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            /** Nombre d'activités regroupées par le projet. */
            'activities_count' => $this->whenCounted('activities'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
