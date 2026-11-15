<?php

namespace App\Http\Resources\V1;

use App\Models\AuditEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditEntry
 */
class AuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Auteur : le CEO, un agent ou le système. */
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'kind' => $this->kind,
            /** Événement, par exemple « agent.position_changed ». */
            'event' => $this->event,
            'subject_id' => $this->subject_id,
            /** Détail de l'événement ; pour une modification, les valeurs avant et après. */
            'properties' => $this->properties,
            'created_at' => $this->created_at,
        ];
    }
}
