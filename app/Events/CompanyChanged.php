<?php

namespace App\Events;

use App\Models\AuditEntry;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Prévient en temps réel les ordinateurs du CEO qu'une donnée de l'entreprise
 * a changé. Un seul événement pour toutes les ressources : il reprend l'entrée
 * du journal de traçabilité, et l'application recharge ce qui la concerne.
 *
 * L'événement ne transporte aucune donnée métier : seulement de quoi savoir
 * quoi recharger par l'API.
 */
class CompanyChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly AuditEntry $entry) {}

    public static function channelFor(int $companyId): string
    {
        return 'company.'.$companyId;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channelFor($this->entry->company_id));
    }

    public function broadcastAs(): string
    {
        return 'company.changed';
    }

    /**
     * @return array{id: int, event: string, kind: string, actor_type: string, subject_id: int|null, occurred_at: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->entry->id,
            'event' => $this->entry->event,
            'kind' => $this->entry->kind->value,
            'actor_type' => $this->entry->actor_type->value,
            'subject_id' => $this->entry->subject_id,
            'occurred_at' => $this->entry->created_at->toIso8601ZuluString('millisecond'),
        ];
    }
}
