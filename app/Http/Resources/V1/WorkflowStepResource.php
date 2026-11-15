<?php

namespace App\Http\Resources\V1;

use App\Models\WorkflowStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowStep
 */
class WorkflowStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Libellé libre de l'étape. */
            'name' => $this->name,
            /** Sens de l'étape pour le logiciel. */
            'category' => $this->category,
            'sort_order' => $this->sort_order,
        ];
    }
}
