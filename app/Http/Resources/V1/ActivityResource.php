<?php

namespace App\Http\Resources\V1;

use App\Models\Activity;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'activity_type_id' => $this->activity_type_id,
            /** Projet de rattachement ; vide pour une activité sans projet. */
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            /** Étape en cours dans le workflow d'activité du type. */
            'workflow_step_id' => $this->workflow_step_id,
            /** Catégorie de l'étape en cours ; vide si le type n'a pas de workflow. */
            'step_category' => $this->step?->category,
            /**
             * Valeurs des champs propres au type, par clé.
             *
             * @var array<string, mixed>
             */
            'field_values' => (object) ($this->field_values ?? []),
            /** Identifiants des équipes mobilisées. */
            'unit_ids' => $this->units->map(fn (Unit $unit): int => $unit->id)->values()->all(),
            'due_at' => $this->due_at,
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
