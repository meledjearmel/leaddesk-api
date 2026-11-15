<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActorType;
use App\Enums\AuditKind;
use App\Http\Resources\V1\AuditEntryResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Journal de traçabilité (RM-34) : consultations, actions et changements.
 *
 * Le journal se lit ; il ne se modifie pas et ne se supprime pas.
 */
#[Group('Journal de traçabilité', weight: 7)]
class AuditEntryController extends ApiController
{
    /**
     * Lire le journal.
     *
     * Du plus récent au plus ancien, 50 entrées par page. Filtrable par
     * nature, auteur, événement (préfixe, par exemple `agent.`) et sujet.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'kind' => ['sometimes', Rule::enum(AuditKind::class)],
            'actor_type' => ['sometimes', Rule::enum(ActorType::class)],
            // Événement exact ou préfixe : « agent. » renvoie tous les événements d'agent.
            'event' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z_.]+$/'],
            // Type du sujet en minuscules, par exemple « agent » ou « unit ».
            'subject' => ['sometimes', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'subject_id' => ['sometimes', 'integer'],
        ]);

        $query = $this->company($request)->auditEntries()->orderByDesc('id');

        if (isset($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        if (isset($filters['actor_type'])) {
            $query->where('actor_type', $filters['actor_type']);
        }

        if (isset($filters['event'])) {
            $event = (string) $filters['event'];

            str_ends_with($event, '.')
                ? $query->where('event', 'like', $event.'%')
                : $query->where('event', $event);
        }

        if (isset($filters['subject'])) {
            $query->where('event', 'like', $filters['subject'].'.%');
        }

        if (isset($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        return AuditEntryResource::collection($query->simplePaginate(50));
    }
}
