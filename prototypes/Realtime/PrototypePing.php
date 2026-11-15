<?php

namespace Prototypes\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Événement de test du prototype 3 : diffusé tout de suite (sans file) sur le
 * canal privé du compte qui l'a demandé.
 */
class PrototypePing implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly string $message,
        public readonly string $sentAt,
    ) {}

    public static function channelFor(int $userId): string
    {
        return 'prototypes.user.'.$userId;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channelFor($this->userId));
    }

    public function broadcastAs(): string
    {
        return 'prototype.ping';
    }

    /**
     * @return array{message: string, sent_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'sent_at' => $this->sentAt,
        ];
    }
}
