<?php

namespace App\Http\Resources\V1;

use App\Models\ActivityType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityType
 */
class ActivityTypeResource extends JsonResource
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
            /** Unité responsable par défaut des activités de ce type. */
            'unit_id' => $this->unit_id,
            /** Workflow suivi par les activités de ce type. */
            'activity_workflow_id' => $this->activity_workflow_id,
            /** Workflow suivi par les tâches de ces activités. */
            'task_workflow_id' => $this->task_workflow_id,
            /**
             * Champs propres à ce type d'activité.
             *
             * @var list<array{key: string, label: string, type: string, required: bool, options: list<string>}>
             */
            'fields' => $this->fields ?? [],
            /**
             * Base de tâches proposée au responsable, qui la reprend, l'adapte ou la remplace.
             *
             * @var list<array{title: string, description: string|null}>
             */
            'task_templates' => $this->task_templates ?? [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
