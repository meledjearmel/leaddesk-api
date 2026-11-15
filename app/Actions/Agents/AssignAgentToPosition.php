<?php

namespace App\Actions\Agents;

use App\Models\Agent;
use App\Models\Position;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Affecte un agent à un poste, ou le retire de tout poste.
 *
 * RM-05 : un agent occupe un seul poste à la fois et garde son identité et
 * son historique. L'affectation en cours est close, une nouvelle est ouverte ;
 * rien n'est effacé. Les accès de l'agent découlent du seul poste actuel.
 */
class AssignAgentToPosition
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(Agent $agent, ?Position $position): Agent
    {
        if ($agent->position_id === $position?->id) {
            return $agent;
        }

        return DB::transaction(function () use ($agent, $position): Agent {
            $now = now();
            $previousId = $agent->position_id;

            $agent->assignments()->whereNull('ended_at')->update(['ended_at' => $now]);

            if ($position !== null) {
                $agent->assignments()->create([
                    'position_id' => $position->id,
                    'started_at' => $now,
                ]);
            }

            $agent->update(['position_id' => $position?->id]);

            $this->audit->record($agent->company_id, 'agent.position_changed', $agent, [
                'from_position_id' => $previousId,
                'to_position_id' => $position?->id,
            ]);

            return $agent;
        });
    }
}
