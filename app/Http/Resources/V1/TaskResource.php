<?php

namespace App\Http\Resources\V1;

use App\Models\Agent;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'activity_id' => $this->activity_id,
            /** Équipe dont le responsable confirme la fin de la tâche. */
            'unit_id' => $this->unit_id,
            'title' => $this->title,
            'description' => $this->description,
            /** Étape en cours dans le workflow de tâche du type. */
            'workflow_step_id' => $this->workflow_step_id,
            /** Catégorie de l'étape en cours ; vide si le type n'a pas de workflow de tâche. */
            'step_category' => $this->step?->category,
            /**
             * Agents affectés, l'agent principal d'abord.
             *
             * @var list<array{agent_id: int, name: string, is_lead: bool}>
             */
            'agents' => $this->agents->map(fn (Agent $agent): array => [
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'is_lead' => (bool) $agent->getRelation('pivot')->getAttribute('is_lead'),
            ])->all(),
            /** Date de confirmation de la fin ; vide tant que la tâche n'est pas confirmée. */
            'completed_at' => $this->completed_at,
            /** Responsable qui a confirmé la fin. */
            'confirmed_by_agent_id' => $this->confirmed_by_agent_id,
            /** Vrai quand le CEO a confirmé lui-même, faute de responsable en service. */
            'confirmed_by_ceo' => $this->confirmed_by_ceo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
