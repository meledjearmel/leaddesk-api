<?php

namespace App\Http\Resources\V1;

use App\Models\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workflow
 */
class WorkflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /** Ce que le workflow fait avancer : des activités ou des tâches. */
            'scope' => $this->scope,
            /** Étapes dans l'ordre du parcours ; la première est l'étape de départ. */
            'steps' => WorkflowStepResource::collection($this->steps),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
