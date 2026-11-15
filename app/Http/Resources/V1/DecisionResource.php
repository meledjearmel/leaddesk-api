<?php

namespace App\Http\Resources\V1;

use App\Models\Decision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Decision
 */
class DecisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Nature de la demande, par exemple « rule_change ». */
            'kind' => $this->kind,
            'title' => $this->title,
            'status' => $this->status,
            /** Agent qui a présenté la demande ; vide si elle vient du système. */
            'requested_by_agent_id' => $this->requested_by_agent_id,
            'requested_by_name' => $this->requestedBy?->name,
            /** Ressource concernée, quand il y en a une. */
            'subject_id' => $this->subject_id,
            /** Contenu de la demande, propre à sa nature. */
            'payload' => (object) ($this->payload ?? []),
            /** Réponse du CEO. */
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
