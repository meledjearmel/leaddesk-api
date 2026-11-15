<?php

namespace App\Http\Resources\V1;

use App\Enums\RunStatus;
use App\Models\TaskRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskRun
 */
class TaskRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hostOnline = $this->device->isOnline();

        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'agent_id' => $this->agent_id,
            /** Ordinateur hôte : celui qui détient les fichiers de la tâche. */
            'device_id' => $this->device_id,
            'device_name' => $this->device->name,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            /** Vrai si l'ordinateur hôte est en ligne. */
            'host_online' => $hostOnline,
            /** Vrai quand l'exécution attend le retour de son ordinateur hôte. */
            'waiting_for_host' => $this->status === RunStatus::Running && ! $hostOnline,
            /** Coût réel cumulé de cette exécution, en millionièmes de dollar. */
            'cost_micros' => (int) $this->usageRecords()->sum('cost_micros'),
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
        ];
    }
}
