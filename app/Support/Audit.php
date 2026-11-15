<?php

namespace App\Support;

use App\Enums\ActorType;
use App\Enums\AuditKind;
use App\Events\CompanyChanged;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Écrit le journal de traçabilité (RM-34).
 *
 * L'auteur par défaut est le CEO connecté, sinon le système. Un autre auteur
 * (un agent, plus tard) se déclare avec {@see Audit::actingAs()}.
 */
class Audit
{
    private ?ActorType $actorType = null;

    private ?int $actorId = null;

    /**
     * Exécute $callback en attribuant les entrées du journal à cet auteur.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function actingAs(ActorType $type, ?int $id, callable $callback): mixed
    {
        [$previousType, $previousId] = [$this->actorType, $this->actorId];
        [$this->actorType, $this->actorId] = [$type, $id];

        try {
            return $callback();
        } finally {
            [$this->actorType, $this->actorId] = [$previousType, $previousId];
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        Company|int $company,
        string $event,
        ?Model $subject = null,
        array $properties = [],
        AuditKind $kind = AuditKind::Change,
    ): AuditEntry {
        [$actorType, $actorId] = $this->currentActor();

        $entry = AuditEntry::query()->create([
            'company_id' => $company instanceof Company ? $company->id : $company,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'kind' => $kind,
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
        ]);

        // Chaque entrée du journal prévient en temps réel les ordinateurs du CEO.
        CompanyChanged::dispatch($entry);

        return $entry;
    }

    /**
     * @return array{ActorType, int|null}
     */
    private function currentActor(): array
    {
        if ($this->actorType !== null) {
            return [$this->actorType, $this->actorId];
        }

        $user = Auth::user();

        return $user instanceof User
            ? [ActorType::Ceo, $user->id]
            : [ActorType::System, null];
    }
}
