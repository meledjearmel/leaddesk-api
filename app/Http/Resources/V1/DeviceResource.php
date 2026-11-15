<?php

namespace App\Http\Resources\V1;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Identifiant généré par l'application à son installation. */
            'installation_id' => $this->installation_id,
            'name' => $this->name,
            'platform' => $this->platform,
            'last_seen_at' => $this->last_seen_at,
            /** Vrai si l'ordinateur a donné signe de vie récemment. */
            'online' => $this->isOnline(),
            'created_at' => $this->created_at,
        ];
    }
}
