<?php

namespace App\Http\Resources\V1;

use App\Models\OfficePlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OfficePlan
 */
class OfficePlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Numéro de version, croissant dans l'entreprise. */
            'version' => $this->version,
            /** `proposed`, `accepted` (en vigueur), `rejected`, `superseded` (remplacé) ou `stale` (périmé). */
            'status' => $this->status->value,
            /**
             * Plan au format `OfficePlan.toJson()` de l'application : `cols`,
             * `rows` et `rooms` (`id`, `type`, `department`, `name`, `x`, `y`, `cols`, `rows`).
             * `department` est l'identifiant (texte) d'une unité de premier niveau.
             *
             * @var array<string, mixed>
             */
            'layout' => $this->layout,
            /** Poste et agent qui ont proposé le plan ; vides quand le CEO l'a dessiné. */
            'proposed_by_position_id' => $this->proposed_by_position_id,
            'proposed_by_agent_id' => $this->proposed_by_agent_id,
            /** Plan en vigueur au moment de la proposition, pour la comparaison. */
            'based_on_id' => $this->based_on_id,
            /** Motif donné par le poste qui propose. */
            'reason' => $this->reason,
            'decision_comment' => $this->decision_comment,
            'decided_at' => $this->decided_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
